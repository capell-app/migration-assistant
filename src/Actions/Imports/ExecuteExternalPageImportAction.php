<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Data\Imports\ExternalPageImportExecutionResult;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Events\ImportCompleting;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static ExternalPageImportExecutionResult run(ExternalImportPreview $preview, ExternalPageImportTargetData|array<string, mixed> $defaultPageAttributes, ?string $sourceFilename = null, ?string $targetLabel = null, ?ImportSession $existingSession = null, bool $finalize = true, ?Authenticatable $actor = null)
 */
final class ExecuteExternalPageImportAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  ExternalPageImportTargetData|array<string, mixed>  $defaultPageAttributes
     */
    public function handle(
        ExternalImportPreview $preview,
        ExternalPageImportTargetData|array $defaultPageAttributes,
        ?string $sourceFilename = null,
        ?string $targetLabel = null,
        ?ImportSession $existingSession = null,
        bool $finalize = true,
        ?Authenticatable $actor = null,
    ): ExternalPageImportExecutionResult {
        $authorizedTarget = AuthorizeExternalPageImportTargetAction::run(
            $this->targetData($defaultPageAttributes),
            $actor,
        );
        $defaultPageAttributes = $authorizedTarget->pageAttributes();

        $this->assertPreviewCanExecute($preview, $defaultPageAttributes);

        $session = $existingSession ?? $this->createSession($preview, $sourceFilename, $targetLabel, $actor);

        try {
            $report = resolve(PageImportService::class)->import(
                $this->packageFromPreview($preview, $defaultPageAttributes),
                new ResolutionMap(resolved: [], unresolved: []),
                is_numeric($session->getRawOriginal('target_id')) ? (int) $session->getRawOriginal('target_id') : null,
                $authorizedTarget->siteId,
            );

            if (! $finalize) {
                return new ExternalPageImportExecutionResult($session->refresh(), $report);
            }

            $failureReason = $report->isSuccess() ? null : implode(' / ', array_slice($report->errors, 0, 5));

            $session->forceFill([
                'result_summary' => $report->toArray(),
                'status' => $report->isSuccess() ? ImportSessionStatus::Running : ImportSessionStatus::Failed,
                'failure_reason' => $failureReason,
            ])->save();

            if ($report->createdPageIds !== []) {
                CreateImportRollbackReportAction::run($session, $report);
            }

            if ($report->isSuccess()) {
                event(new ImportCompleting($session->refresh()));

                $session->forceFill([
                    'status' => ImportSessionStatus::Completed,
                    'executed_at' => now(),
                ])->save();

                event(new ImportCompleted($session));
            } else {
                $session->forceFill(['executed_at' => now()])->save();
                event(new ImportFailed($session, (string) $failureReason));
            }

            return new ExternalPageImportExecutionResult($session->refresh(), $report);
        } catch (Throwable $throwable) {
            if (! $finalize) {
                throw $throwable;
            }

            $session->forceFill([
                'status' => ImportSessionStatus::Failed,
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            event(new ImportFailed($session, $throwable->getMessage()));

            throw $throwable;
        }
    }

    private function createSession(
        ExternalImportPreview $preview,
        ?string $sourceFilename,
        ?string $targetLabel,
        ?Authenticatable $actor,
    ): ImportSession {
        $target = resolve(PageImportTargetResolver::class)->create(
            $targetLabel ?? (string) __('migration-assistant::imports.external_default_target_label'),
        );

        return ImportSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $actor?->getAuthIdentifier() ?? auth()->id(),
            'target_type' => $target->type,
            'target_id' => is_int($target->id) ? $target->id : null,
            'target_label' => $target->label,
            'target_url' => $target->url,
            'kind' => ImportSessionKind::PageImport,
            'status' => ImportSessionStatus::Running,
            'source_environment' => 'external',
            'source_filename' => $sourceFilename,
            'manifest' => [
                'package_type' => 'external-page-import',
                'target' => $preview->target,
                'creates' => $preview->creates,
                'skips' => $preview->skips,
            ],
            'resolution_map' => ['resolved' => [], 'unresolved' => []],
            'page_decisions' => $this->pageDecisionsFromPreview($preview),
            'relation_decisions' => [],
            'validation_results' => ['blocking_errors' => []],
            'reviewed_at' => now(),
            'validated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaultPageAttributes
     */
    private function assertPreviewCanExecute(ExternalImportPreview $preview, array $defaultPageAttributes): void
    {
        if ($preview->target !== 'page') {
            throw new RuntimeException((string) __('migration-assistant::imports.external_page_target_required', [
                'target' => $preview->target,
            ]));
        }

        if ($preview->errors !== []) {
            throw new RuntimeException((string) __('migration-assistant::imports.external_preview_has_errors', [
                'errors' => implode(' / ', $preview->errors),
            ]));
        }

        foreach ($preview->rows as $row) {
            if (($row['action'] ?? null) !== 'create') {
                continue;
            }

            $attributes = array_replace_recursive(
                is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
                $defaultPageAttributes,
            );
            $missing = $this->missingRequiredPageAttributes($attributes);

            if ($missing === []) {
                $missing = $this->missingPageReferences($attributes);
            }

            if ($missing !== []) {
                throw new RuntimeException((string) __('migration-assistant::imports.external_page_attributes_required', [
                    'row' => $this->stringFromScalar($row['row'] ?? null) ?? '?',
                    'attributes' => implode(', ', $missing),
                ]));
            }
        }
    }

    /**
     * @param  ExternalPageImportTargetData|array<string, mixed>  $target
     */
    private function targetData(ExternalPageImportTargetData|array $target): ExternalPageImportTargetData
    {
        if ($target instanceof ExternalPageImportTargetData) {
            return $target;
        }

        foreach (['site_id', 'layout_id', 'blueprint_id', 'language_id'] as $key) {
            if (! is_numeric($target[$key] ?? null)) {
                throw new RuntimeException((string) __('migration-assistant::imports.external_page_attributes_required', [
                    'row' => '?',
                    'attributes' => $key,
                ]));
            }
        }

        return new ExternalPageImportTargetData(
            siteId: (int) $target['site_id'],
            layoutId: (int) $target['layout_id'],
            blueprintId: (int) $target['blueprint_id'],
            languageId: (int) $target['language_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function missingRequiredPageAttributes(array $attributes): array
    {
        $required = ['name', 'blueprint_id', 'layout_id', 'site_id'];
        $missing = [];

        foreach ($required as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if ($value === null || $value === '') {
                $missing[] = $attribute;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function missingPageReferences(array $attributes): array
    {
        $references = [
            'blueprint_id' => Blueprint::class,
            'layout_id' => Layout::class,
            'site_id' => Site::class,
        ];
        $missing = [];

        foreach ($references as $attribute => $modelClass) {
            if (! $modelClass::query()->whereKey($attributes[$attribute])->exists()) {
                $missing[] = $attribute;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $defaultPageAttributes
     */
    private function packageFromPreview(ExternalImportPreview $preview, array $defaultPageAttributes): PackageReadResult
    {
        $payload = [];

        foreach ($preview->rows as $row) {
            if (($row['action'] ?? null) !== 'create') {
                continue;
            }

            $rowNumber = is_numeric($row['row'] ?? null) ? (int) $row['row'] : count($payload) + 1;
            $attributes = array_replace_recursive(
                is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
                $defaultPageAttributes,
            );
            $sourceParentId = $this->sourceParentIdFrom($attributes);

            if ($sourceParentId !== null && ($attributes['parent_id'] ?? null) === null) {
                $attributes['parent_id'] = $sourceParentId;
            }

            $payload[sprintf('pages/external-row-%d.json', $rowNumber)] = json_encode([
                'type' => 'page',
                'uuid' => (string) Str::uuid(),
                'id' => $this->sourceIdFrom($attributes, $rowNumber),
                'attributes' => $attributes,
                'owned_relations' => ['page_urls' => $this->pageUrlsFrom($attributes)],
                'shared_relations' => [],
                'media_bindings' => [],
            ], JSON_THROW_ON_ERROR);
        }

        return new PackageReadResult(
            archivePath: '',
            manifest: [
                'package_type' => 'external-page-import',
                'target' => $preview->target,
                'creates' => $preview->creates,
                'skips' => $preview->skips,
            ],
            integrity: [],
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sourceIdFrom(array $attributes, int $rowNumber): int|string
    {
        $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $imported = is_array($meta['imported'] ?? null) ? $meta['imported'] : [];
        $sourceIdentity = $wordpress['source_identity'] ?? $imported['source_identity'] ?? null;

        if (is_string($sourceIdentity) && trim($sourceIdentity) !== '') {
            return $sourceIdentity;
        }

        return $rowNumber;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sourceParentIdFrom(array $attributes): int|string|null
    {
        $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
        $wordpress = is_array($meta['wordpress'] ?? null) ? $meta['wordpress'] : [];
        $imported = is_array($meta['imported'] ?? null) ? $meta['imported'] : [];
        $wordpressParentId = $wordpress['parent_id'] ?? null;

        if (is_string($wordpressParentId) && trim($wordpressParentId) !== '' && trim($wordpressParentId) !== '0') {
            return 'wordpress:' . trim($wordpressParentId);
        }

        $importedParentId = $imported['parent_id'] ?? null;

        if (is_int($importedParentId) || is_string($importedParentId)) {
            return $importedParentId;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array<string, mixed>>
     */
    private function pageUrlsFrom(array $attributes): array
    {
        $meta = is_array($attributes['meta'] ?? null) ? $attributes['meta'] : [];
        $slug = $meta['slug'] ?? null;

        if (! is_string($slug) || trim($slug) === '') {
            return [];
        }

        $languageId = $attributes['language_id'] ?? Site::query()->whereKey($attributes['site_id'] ?? null)->value('language_id');

        if ($languageId === null || $languageId === '') {
            return [];
        }

        return [[
            'language_id' => $languageId,
            'url' => '/' . trim(Str::slug($slug), '/'),
            'target_url' => null,
            'status' => true,
            'is_manual' => false,
        ]];
    }

    /**
     * @return array<string, array{action: string}>
     */
    private function pageDecisionsFromPreview(ExternalImportPreview $preview): array
    {
        $decisions = [];

        foreach ($preview->rows as $row) {
            $rowNumber = is_numeric($row['row'] ?? null) ? (int) $row['row'] : count($decisions) + 1;
            $decisions['external-row-' . $rowNumber] = ['action' => $this->stringFromScalar($row['action'] ?? null) ?? 'create'];
        }

        return $decisions;
    }

    private function stringFromScalar(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return null;
    }
}
