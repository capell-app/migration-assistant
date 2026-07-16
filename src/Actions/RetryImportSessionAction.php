<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
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
 * Guard rails: the session must be in the `Failed` state, must still
 * have the resolution_map / decisions, and the source archive must
 * still be present on the configured migration-assistant disk.
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
