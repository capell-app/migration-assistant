<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * @method static RollbackExecutionResultData run(ImportRollbackReport $report, ?Authenticatable $actor = null, bool $dryRun = false)
 */
final class ExecuteImportRollbackAction
{
    use AsAction;

    public function handle(
        ImportRollbackReport $report,
        ?Authenticatable $actor = null,
        bool $dryRun = false,
    ): RollbackExecutionResultData {
        return DB::transaction(function () use ($report, $actor, $dryRun): RollbackExecutionResultData {
            $lockedReport = ImportRollbackReport::query()
                ->with('importSession')
                ->lockForUpdate()
                ->findOrFail($report->getKey());
            $provenance = is_array($lockedReport->provenance) ? $lockedReport->provenance : [];

            if (! $this->hasValidProvenance($lockedReport, $provenance)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            $entries = $provenance['entries'] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            $matched = 0;
            $skipped = [];
            $deletable = [];

            foreach (array_reverse($entries) as $entry) {
                if (! is_array($entry) || ! $this->isValidEntry($entry)) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
                }

                $model = RollbackProvenance::findEntryModel($entry, lockForUpdate: true);

                if (! $model instanceof Model) {
                    $skipped[] = $this->skip($entry, 'missing');

                    continue;
                }

                $matched++;
                $reason = RollbackProvenance::matchesEntry($model, $entry);

                if ($reason !== null) {
                    $skipped[] = $this->skip($entry, $reason);

                    continue;
                }

                if (! $this->actorCanDelete($actor, $model, (int) $entry['site_id'])) {
                    $skipped[] = $this->skip($entry, 'unauthorized');

                    continue;
                }

                $deletable[] = $model;
            }

            $deleted = 0;

            if (! $dryRun) {
                foreach ($deletable as $model) {
                    $model->delete();
                    $deleted++;
                }

                $summary = is_array($lockedReport->summary) ? $lockedReport->summary : [];
                $summary['rollback_execution'] = [
                    'deleted' => $deleted,
                    'executed_at' => now()->toISOString(),
                    'matched' => $matched,
                    'skipped' => $skipped,
                ];

                $lockedReport->forceFill(['summary' => $summary])->save();
            }

            $result = new RollbackExecutionResultData(
                matched: $matched,
                deleted: $deleted,
                skipped: $skipped,
                dryRun: $dryRun,
            );

            $this->audit($lockedReport, $actor, $result, $skipped === [] ? 'completed' : 'completed_with_skips');

            return $result;
        });
    }

    /**
     * @param  array<array-key, mixed>  $provenance
     */
    private function hasValidProvenance(ImportRollbackReport $report, array $provenance): bool
    {
        $session = $report->importSession;

        return $session !== null
            && ($provenance['version'] ?? null) === 1
            && ($provenance['report_uuid'] ?? null) === $report->uuid
            && ($provenance['session_uuid'] ?? null) === $session->uuid
            && ($provenance['session_user_id'] ?? null) === $session->user_id
            && is_string($provenance['executed_at'] ?? null)
            && RollbackProvenance::hasValidSignature($provenance, $report->provenance_signature);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function isValidEntry(array $entry): bool
    {
        return is_string($entry['type'] ?? null)
            && (is_int($entry['id'] ?? null) || is_string($entry['id'] ?? null))
            && is_int($entry['site_id'] ?? null)
            && is_string($entry['created_at'] ?? null)
            && is_string($entry['updated_at'] ?? null);
    }

    private function actorCanDelete(?Authenticatable $actor, Model $model, int $siteId): bool
    {
        return $actor !== null
            && $this->actorCanAccessSite($actor, $siteId)
            && Gate::forUser($actor)->allows('delete', $model);
    }

    private function actorCanAccessSite(Authenticatable $actor, int $siteId): bool
    {
        try {
            if (method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin() === true) {
                return true;
            }

            if (! method_exists($actor, 'getAssignedSiteIds')) {
                return false;
            }

            $assignedSiteIds = $actor->getAssignedSiteIds();

            return $assignedSiteIds instanceof Collection && $assignedSiteIds->contains($siteId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return array{type: string, id: int|string, reason: string}
     */
    private function skip(array $entry, string $reason): array
    {
        return [
            'type' => (string) $entry['type'],
            'id' => $entry['id'],
            'reason' => $reason,
        ];
    }

    private function rejectedResult(
        ImportRollbackReport $report,
        ?Authenticatable $actor,
        bool $dryRun,
        string $reason,
    ): RollbackExecutionResultData {
        $result = new RollbackExecutionResultData(
            matched: 0,
            deleted: 0,
            skipped: [['type' => 'report', 'id' => $report->getKey(), 'reason' => $reason]],
            dryRun: $dryRun,
        );

        $this->audit($report, $actor, $result, 'rejected');

        return $result;
    }

    private function audit(
        ImportRollbackReport $report,
        ?Authenticatable $actor,
        RollbackExecutionResultData $result,
        string $outcome,
    ): void {
        $actorId = $actor?->getAuthIdentifier();

        ImportRollbackAudit::query()->create([
            'import_rollback_report_id' => $report->getKey(),
            'actor_id' => is_int($actorId) ? $actorId : null,
            'dry_run' => $result->dryRun,
            'outcome' => $outcome,
            'matched' => $result->matched,
            'deleted' => $result->deleted,
            'skipped' => $result->skipped,
        ]);
    }
}
