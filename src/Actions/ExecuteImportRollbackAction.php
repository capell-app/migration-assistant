<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use BackedEnum;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
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
            $actor = $this->freshActor($actor);
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

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! $this->isValidEntry($entry)) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
                }
            }

            if (! $this->actorCanRollbackEntries($actor, $entries)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'unauthorized');
            }

            $matched = 0;
            $skipped = [];
            $deletable = [];

            foreach (array_reverse($entries) as $entry) {
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

                if (! Gate::forUser($actor)->allows('delete', $model)) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'unauthorized');
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
            && $this->hasExactKeys($provenance, [
                'entries',
                'executed_at',
                'report_uuid',
                'session_user_id',
                'session_uuid',
                'version',
            ])
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
        $type = $entry['type'] ?? null;

        return $this->hasExactKeys($entry, ['created_at', 'id', 'site_id', 'type', 'updated_at'])
            && is_string($type)
            && RollbackProvenance::isAllowedType($type)
            && (is_int($entry['id'] ?? null) || is_string($entry['id'] ?? null))
            && is_int($entry['site_id'] ?? null)
            && is_string($entry['created_at'] ?? null)
            && is_string($entry['updated_at'] ?? null);
    }

    /**
     * @param  list<array<array-key, mixed>>  $entries
     */
    private function actorCanRollbackEntries(?Authenticatable $actor, array $entries): bool
    {
        if ($actor === null || ! $this->actorIsActive($actor)) {
            return false;
        }

        if ($entries === []) {
            return $this->isGlobalActor($actor) && $this->hasGlobalRollbackPermission($actor);
        }

        $siteIds = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) $entry['site_id'],
            $entries,
        )));

        foreach ($siteIds as $siteId) {
            if (! $this->actorCanRollbackSite($actor, $siteId)) {
                return false;
            }
        }

        return true;
    }

    private function actorCanRollbackSite(Authenticatable $actor, int $siteId): bool
    {
        try {
            if ($this->isGlobalActor($actor)) {
                return $this->hasGlobalRollbackPermission($actor);
            }

            if (! method_exists($actor, 'getAssignedSiteIds')) {
                return false;
            }

            $assignedSiteIds = $actor->getAssignedSiteIds();

            if (! $assignedSiteIds instanceof Collection || ! $assignedSiteIds->contains($siteId)) {
                return false;
            }

            if (! method_exists($actor, 'hasPermissionForSite')) {
                return $this->hasGlobalRollbackPermission($actor);
            }

            $site = Site::query()->withoutGlobalScopes()->find($siteId);

            return $site instanceof Site
                && $actor->hasPermissionForSite($site, MigrationAssistantPermission::ImportSessionRollback->value) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasGlobalRollbackPermission(Authenticatable $actor): bool
    {
        if (! method_exists($actor, 'checkPermissionTo')) {
            return false;
        }

        try {
            return $actor->checkPermissionTo(MigrationAssistantPermission::ImportSessionRollback->value) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isGlobalActor(Authenticatable $actor): bool
    {
        try {
            return method_exists($actor, 'isGlobalAdmin') && $actor->isGlobalAdmin() === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function actorIsActive(Authenticatable $actor): bool
    {
        if (method_exists($actor, 'isActive') && $actor->isActive() !== true) {
            return false;
        }

        if (! $actor instanceof Model) {
            return true;
        }

        $attributes = $actor->getAttributes();

        foreach (['is_active', 'active'] as $attribute) {
            if (array_key_exists($attribute, $attributes) && $attributes[$attribute] === false) {
                return false;
            }
        }

        if (($attributes['disabled_at'] ?? null) !== null || ($attributes['deactivated_at'] ?? null) !== null) {
            return false;
        }

        $status = $attributes['status'] ?? null;
        $status = $status instanceof BackedEnum ? $status->value : $status;

        return ! is_string($status)
            || ! in_array(mb_strtolower($status), ['disabled', 'inactive', 'suspended', 'blocked'], true);
    }

    private function freshActor(?Authenticatable $actor): ?Authenticatable
    {
        if (! $actor instanceof Model) {
            return $actor;
        }

        $freshActor = $actor->newQuery()->find($actor->getAuthIdentifier());

        return $freshActor instanceof Authenticatable ? $freshActor : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
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
            rejected: true,
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
