<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\ImportRetentionReportData;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/** @method static ImportRetentionReportData run(bool $prune = false) */
final class PruneImportEvidenceAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $prune = false): ImportRetentionReportData
    {
        $days = config('migration-assistant.retention.days', 30);
        $days = is_numeric($days) ? max(1, (int) $days) : 30;

        $report = new ImportRetentionReportData($days, ! $prune);
        $diskName = config('migration-assistant.disk', 'local');
        $disk = Storage::disk(is_string($diskName) ? $diskName : 'local');
        $cutoff = now()->subDays($days);

        $countedPaths = [];

        foreach (ImportSession::query()->lazyById() as $candidate) {
            $report->sessions++;
            try {
                $pruned = DB::transaction(function () use ($candidate, $report, $disk, $cutoff, $prune, &$countedPaths): bool {
                    $session = ImportSession::query()->lockForUpdate()->find($candidate->id);
                    if (! $session instanceof ImportSession) {
                        return false;
                    }

                    $path = $session->source_package_path;
                    $exists = is_string($path) && $path !== '' && $disk->exists($path);
                    $bytes = $exists ? $disk->size($path) : 0;
                    if (is_string($path) && ! isset($countedPaths[$path])) {
                        $report->archiveBytes += $bytes;
                        $countedPaths[$path] = true;
                    }

                    if ($session->legal_hold || ! $session->status->isTerminal()
                        || $session->updated_at === null || $session->updated_at->greaterThanOrEqualTo($cutoff)
                        || RetryImportSessionAction::hasUnresolvedCreatedModels($session)) {
                        return false;
                    }

                    // No current executor writes working_dir. Preserve legacy working
                    // directories rather than recursively deleting an unverified path.
                    if ($session->working_dir !== null && $session->working_dir !== '') {
                        throw new RuntimeException('Unmanaged import working directory.');
                    }

                    if (is_string($path) && $path !== '') {
                        $root = config('migration-assistant.paths.imports', 'migration-assistant/imports');
                        if (! is_string($root) || $root === '' || ! str_starts_with($path, trim($root, '/') . '/')
                            || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
                            throw new RuntimeException('Archive is outside the import directory.');
                        }

                        // Paths are encrypted, so compare hydrated values. Shared sources
                        // must remain available to every other session, regardless of age.
                        foreach (ImportSession::query()->whereKeyNot($session->id)->lazyById() as $other) {
                            if ($other->source_package_path === $path) {
                                throw new RuntimeException('Archive is shared by another session.');
                            }
                        }
                    }

                    $report->eligibleSessionIds[] = $session->id;
                    $report->eligibleBytes += $bytes;
                    if (! $prune) {
                        return false;
                    }

                    // Delete files first: a storage failure must retain the complete
                    // recovery trail. A missing file on a later attempt is harmless.
                    if ($exists && (! $disk->delete($path) || $disk->exists($path))) {
                        throw new RuntimeException('Import archive could not be deleted.');
                    }

                    $session->rollbackReports()->delete();
                    if ($session->delete() !== true) {
                        throw new RuntimeException('Import session could not be deleted.');
                    }

                    return true;
                });
                if ($pruned) {
                    $report->pruned++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $report->failedSessionIds[] = $candidate->id;
            }
        }

        return $report;
    }
}
