<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Re-dispatches the execute plan for a failed import session. Used by
 * the Recovery Center ImportSessionResource retry header action (§6.8).
 *
 * Guard rails: the session must be in the `Failed` state. Registered executors
 * own their retry contract; built-in archive replay additionally requires its
 * decisions and archive, with prior created content resolved by rollback.
 */
final class RetryImportSessionAction
{
    use AsFake;
    use AsObject;

    public static function canRetry(ImportSession $session): bool
    {
        if ($session->status !== ImportSessionStatus::Failed) {
            return false;
        }

        $executor = resolve(ImportSessionExecutorRegistry::class)->executorFor($session);

        if ($executor !== null) {
            return $executor->canRetry($session);
        }

        if (self::hasUnresolvedCreatedModels($session)) {
            return false;
        }

        if ($session->resolution_map === null || $session->page_decisions === null || $session->relation_decisions === null) {
            return false;
        }

        $archivePath = (string) $session->source_package_path;

        return $archivePath !== '' && self::archiveDisk()->exists($archivePath);
    }

    public function handle(ImportSession $session): ImportSession
    {
        throw_if($session->status !== ImportSessionStatus::Failed, RuntimeException::class, 'Only failed sessions can be retried.');

        $executor = resolve(ImportSessionExecutorRegistry::class)->executorFor($session);

        if ($executor !== null) {
            throw_unless($executor->canRetry($session), RuntimeException::class, 'Import session source data is no longer present and cannot be retried.');

            return $this->dispatchRetry($session);
        }

        throw_if(self::hasUnresolvedCreatedModels($session), RuntimeException::class, 'Created content must be rolled back before this import session can be retried.');
        throw_if($session->resolution_map === null || $session->page_decisions === null || $session->relation_decisions === null, RuntimeException::class, 'Session is missing resolution data and cannot be retried.');

        $archivePath = (string) $session->source_package_path;
        throw_if($archivePath === '' || ! self::archiveDisk()->exists($archivePath), RuntimeException::class, 'Source archive is no longer present on disk.');

        return $this->dispatchRetry($session);
    }

    private static function archiveDisk(): Filesystem
    {
        $diskName = config('migration-assistant.disk', 'local');

        return Storage::disk(is_string($diskName) ? $diskName : 'local');
    }

    private static function hasUnresolvedCreatedModels(ImportSession $session): bool
    {
        $session = $session->fresh();

        if (! $session instanceof ImportSession) {
            return true;
        }

        $resolvedModels = [];

        foreach ($session->rollbackReports()->get() as $rollbackReport) {
            if (! self::rollbackReportIsResolved($rollbackReport)) {
                return true;
            }

            foreach ($rollbackReport->created_models ?? [] as $createdModel) {
                if (! is_array($createdModel) || ! is_string($createdModel['class'])
                    || (! is_int($createdModel['id']) && ! is_string($createdModel['id']))) {
                    return true;
                }

                $resolvedModels[$createdModel['class']][(string) $createdModel['id']] = true;
            }
        }

        // A process can die after saving the result but before persisting its
        // rollback report. An older resolved report cannot cover that new content.
        foreach ([
            'created_page_ids' => Page::class,
            'created_site_ids' => Site::class,
            'created_site_domain_ids' => SiteDomain::class,
        ] as $field => $modelClass) {
            $createdIds = $session->result_summary[$field] ?? [];

            if (! is_array($createdIds) || ! array_is_list($createdIds)) {
                return true;
            }

            foreach ($createdIds as $createdId) {
                if ((! is_int($createdId) && ! is_string($createdId)) || ! isset($resolvedModels[$modelClass][(string) $createdId])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function rollbackReportIsResolved(ImportRollbackReport $rollbackReport): bool
    {
        $createdModels = $rollbackReport->created_models;

        if (! is_array($createdModels) || ! array_is_list($createdModels)) {
            return false;
        }

        if ($createdModels === []) {
            return true;
        }

        $summary = $rollbackReport->summary;
        $execution = is_array($summary) ? ($summary['rollback_execution'] ?? null) : null;

        if (! is_array($execution)) {
            return false;
        }

        $deleted = $execution['deleted'] ?? null;
        $skipped = $execution['skipped'] ?? null;

        if (! is_int($deleted) || $deleted < 0 || ! is_array($skipped) || ! array_is_list($skipped)) {
            return false;
        }

        foreach ($skipped as $entry) {
            if (! is_array($entry) || ($entry['reason'] ?? null) !== 'missing') {
                return false;
            }
        }

        return $deleted + count($skipped) === count($createdModels);
    }

    private function dispatchRetry(ImportSession $session): ImportSession
    {
        $claimedSession = ClaimImportSessionForExecutionAction::run(
            $session,
            ImportSessionStatus::Queued,
            [ImportSessionStatus::Failed],
        );

        if (! $claimedSession instanceof ImportSession) {
            return $session->refresh();
        }

        dispatch(new ExecuteImportPlanJob((int) $claimedSession->getKey()));

        return $claimedSession->refresh();
    }
}
