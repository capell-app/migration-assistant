<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Actions\BuildPageReviewRows;
use Capell\MigrationAssistant\Actions\BuildRelationResolveRowsAction;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ManifestValidator;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\ResolutionMapBuilder;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * @method static PageImportWizardStateData run(array $state)
 */
final class StartPageImportAction
{
    use AsAction;

    public const string ERROR_UPLOAD_REQUIRED = 'upload_required';

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(array $state): PageImportWizardStateData
    {
        $archiveDiskPath = $this->archiveDiskPathFrom($state);
        throw_if($archiveDiskPath === '', RuntimeException::class, self::ERROR_UPLOAD_REQUIRED);

        $package = (new PackageReader)->read(Storage::disk('local')->path($archiveDiskPath));

        $validation = (new ManifestValidator)->validate($package->manifest);
        if (! $validation->isValid()) {
            throw new RuntimeException(implode(' / ', $validation->errors));
        }

        $resolutionMap = (new ResolutionMapBuilder(
            resolve(RelationMatchResolverRegistry::class),
        ))->build($package->payload);

        $reviewRows = resolve(BuildPageReviewRows::class)->run($package, $resolutionMap);
        $resolveRows = BuildRelationResolveRowsAction::run($resolutionMap);

        $target = resolve(PageImportTargetResolver::class)->create($this->workspaceNameFrom($state));

        $session = ImportSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => auth()->id(),
            'target_type' => $target->type,
            'target_id' => is_int($target->id) ? $target->id : null,
            'target_label' => $target->label,
            'target_url' => $target->url,
            'kind' => ImportSessionKind::PageImport,
            'status' => $resolutionMap->hasUnresolved() ? ImportSessionStatus::Mapped : ImportSessionStatus::Parsed,
            'source_filename' => $this->sourceFilenameFrom($state),
            'source_package_path' => $archiveDiskPath,
            'manifest' => $package->manifest,
            'resolution_map' => $resolutionMap->toArray(),
        ]);

        if ($target->legacyWorkspaceId !== null) {
            $session->setAttribute('workspace_id', $target->legacyWorkspaceId);
            $session->save();
        }

        return new PageImportWizardStateData(
            step: 'review',
            sessionId: (int) $session->getKey(),
            reviewRows: array_map(
                static fn (PageReviewRow $row): array => $row->toArray(),
                $reviewRows,
            ),
            pageDecisions: $this->pageDecisionsFromReviewRows($reviewRows),
            resolveRows: array_map(
                static fn (RelationResolveRow $row): array => $row->toArray(),
                $resolveRows,
            ),
            relationDecisions: $this->relationDecisionsFromResolveRows($resolveRows),
            notice: $resolutionMap->hasUnresolved()
                ? PageImportWizardStateData::NOTICE_UNRESOLVED_REFERENCES
                : null,
            noticeCount: $resolutionMap->hasUnresolved() ? count($resolutionMap->unresolved) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function archiveDiskPathFrom(array $state): string
    {
        if (is_array($state['archive'] ?? null)) {
            return (string) array_values($state['archive'])[0];
        }

        return (string) ($state['archive'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function sourceFilenameFrom(array $state): ?string
    {
        if (is_array($state['archive_filename'] ?? null)) {
            return (string) array_values($state['archive_filename'])[0];
        }

        $sourceFilename = $state['archive_filename'] ?? null;

        return is_string($sourceFilename) && $sourceFilename !== '' ? $sourceFilename : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function workspaceNameFrom(array $state): string
    {
        $workspaceName = (string) ($state['workspace_name'] ?? '');

        if ($workspaceName !== '') {
            return $workspaceName;
        }

        return sprintf(
            '%s — %s',
            __('capell-admin::exchanger.import_workspace_default_name'),
            now()->format('Y-m-d H:i'),
        );
    }

    /**
     * @param  list<PageReviewRow>  $reviewRows
     * @return array<string, array{action: string}>
     */
    private function pageDecisionsFromReviewRows(array $reviewRows): array
    {
        $decisions = [];

        foreach ($reviewRows as $row) {
            $decisions[$row->uuid] = ['action' => $row->suggestedAction];
        }

        return $decisions;
    }

    /**
     * @param  list<RelationResolveRow>  $resolveRows
     * @return array<string, array{action: string, target_id?: int|string|null}>
     */
    private function relationDecisionsFromResolveRows(array $resolveRows): array
    {
        $decisions = [];

        foreach ($resolveRows as $row) {
            $decisions[$row->ref] = [
                'action' => $row->suggestedAction,
                'target_id' => $row->topMatch['local_id'] ?? null,
            ];
        }

        return $decisions;
    }
}
