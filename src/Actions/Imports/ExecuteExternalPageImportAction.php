<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Capell\MigrationAssistant\Data\Imports\ExternalPageImportExecutionResult;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * @method static ExternalPageImportExecutionResult run(ExternalImportPreview $preview, array<string, mixed> $defaultPageAttributes = [], ?string $sourceFilename = null, ?string $targetLabel = null)
 */
final class ExecuteExternalPageImportAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $defaultPageAttributes
     */
    public function handle(
        ExternalImportPreview $preview,
        array $defaultPageAttributes = [],
        ?string $sourceFilename = null,
        ?string $targetLabel = null,
    ): ExternalPageImportExecutionResult {
        $this->assertPreviewCanExecute($preview, $defaultPageAttributes);

        $target = resolve(PageImportTargetResolver::class)->create(
            $targetLabel ?? (string) __('migration-assistant::imports.external_default_target_label'),
        );

        $session = ImportSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => auth()->id(),
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
            'validation_results' => ['blocking_errors' => []],
            'reviewed_at' => now(),
            'validated_at' => now(),
        ]);

        try {
            $report = resolve(PageImportService::class)->import(
                $this->packageFromPreview($preview, $defaultPageAttributes),
                new ResolutionMap(resolved: [], unresolved: []),
                is_int($target->id) ? $target->id : null,
            );

            $failureReason = $report->isSuccess() ? null : implode(' / ', array_slice($report->errors, 0, 5));

            $session->forceFill([
                'result_summary' => $report->toArray(),
                'status' => $report->isSuccess() ? ImportSessionStatus::Completed : ImportSessionStatus::Failed,
                'failure_reason' => $failureReason,
                'executed_at' => now(),
            ])->save();

            if ($report->createdPageIds !== []) {
                CreateImportRollbackReportAction::run($session, $report);
            }

            if ($report->isSuccess()) {
                event(new ImportCompleted($session));
            } else {
                event(new ImportFailed($session, (string) $failureReason));
            }

            return new ExternalPageImportExecutionResult($session->refresh(), $report);
        } catch (Throwable $throwable) {
            $session->forceFill([
                'status' => ImportSessionStatus::Failed,
                'failure_reason' => $throwable->getMessage(),
            ])->save();

            event(new ImportFailed($session, $throwable->getMessage()));

            throw $throwable;
        }
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
                $defaultPageAttributes,
                is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
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
                $defaultPageAttributes,
                is_array($row['attributes'] ?? null) ? $row['attributes'] : [],
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
