<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use BackedEnum;
use Capell\Core\Models\Site;
use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Data\DatabaseImportWritePlan;
use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Capell\MigrationAssistant\Support\RollbackPurgeGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use UnexpectedValueException;

/**
 * @method static RollbackExecutionResultData run(ImportRollbackReport $report, ?Authenticatable $actor = null, bool $dryRun = false)
 */
final class ExecuteImportRollbackAction
{
    use AsFake;
    use AsObject;

    public function handle(
        ImportRollbackReport $report,
        ?Authenticatable $actor = null,
        bool $dryRun = false,
    ): RollbackExecutionResultData {
        $provenance = $report->provenance;
        if (is_array($provenance) && array_key_exists('write_plan', $provenance)) {
            if (! RollbackProvenance::hasValidSignature($provenance, $report->provenance_signature)) {
                throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid signed in-place rollback plan.');
            }

            $plan = DatabaseImportWritePlan::fromArray(is_array($provenance['write_plan']) ? $provenance['write_plan'] : []);

            return $plan->within(fn (): RollbackExecutionResultData => $this->executeBound($report, $actor, $dryRun, $plan));
        }

        return $this->executeBound($report, $actor, $dryRun, null);
    }

    private function executeBound(ImportRollbackReport $report, ?Authenticatable $actor, bool $dryRun, ?DatabaseImportWritePlan $plan): RollbackExecutionResultData
    {
        $connection = DB::connection($plan?->writerConnection);
        if ($plan instanceof DatabaseImportWritePlan && $actor instanceof Model) {
            $actor = (clone $actor)->setConnection($plan->readerConnection);
        }

        $siteIds = [];
        $result = $connection->transaction(function () use ($report, $actor, $dryRun, &$siteIds, $connection, $plan): RollbackExecutionResultData {
            $actor = $this->freshActor($actor);
            $reportKey = $this->scalarKey($report);
            $lockedReport = ImportRollbackReport::on($plan?->writerConnection)
                ->with('importSession')
                ->lockForUpdate()
                ->find($reportKey);

            if (! $lockedReport instanceof ImportRollbackReport) {
                throw new UnexpectedValueException('Rollback report no longer exists.');
            }

            $provenance = is_array($lockedReport->provenance) ? $lockedReport->provenance : [];

            if (! $this->hasValidProvenance($lockedReport, $provenance)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            if ($plan instanceof DatabaseImportWritePlan) {
                $savedManifest = $lockedReport->importSession?->manifest;
                if (! is_array($savedManifest) || ($savedManifest['write_plan'] ?? null) !== $plan->toArray()) {
                    throw InPlaceDatabaseRefusalReason::Invalid->exception('The rollback physical plan does not match the imported session.');
                }
            }

            $entries = $provenance['entries'] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            $version = $provenance['version'] ?? null;
            if (! is_int($version)) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            $images = $lockedReport->before_images;
            if ($version === 2 && (! is_array($images) || ! array_is_list($images) || count($images) !== count($entries))) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
            }

            foreach ($entries as $index => $entry) {
                if ($plan instanceof DatabaseImportWritePlan && $plan->executionMode !== 'dedicated-process' && (! is_array($entry) || ($entry['type'] ?? null) !== 'setting' || ($entry['site_id'] ?? null) !== null || count($plan->targets) !== 1)) {
                    throw InPlaceDatabaseRefusalReason::Target->exception('The rollback target is outside the in-place settings plan.');
                }

                if (! is_array($entry) || ! $this->isValidEntry($entry, $version) || ($version === 2 && ((! is_string($entry['before_hash'] ?? null) || ! hash_equals($entry['before_hash'], hash('sha256', json_encode($images[$index], JSON_THROW_ON_ERROR)))) || (($entry['operation'] === 'created') !== ($images[$index] === null)) || ($images[$index] !== null && ! is_array($images[$index]))))) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
                }
            }

            $authorized = $plan instanceof DatabaseImportWritePlan
                ? $actor instanceof Model && $this->actorIsActive($actor) && resolve(AuthorizeInPlaceRollbackActorAction::class)->handle($plan->readerConnection, $actor)
                : (($version !== 2 || ! (! $actor instanceof Authenticatable || ! $this->isGlobalActor($actor) || ! $this->hasGlobalRollbackPermission($actor))) && $this->actorCanRollbackEntries($actor, $entries));
            if (! $authorized) {
                return $this->rejectedResult($lockedReport, $actor, $dryRun, 'unauthorized');
            }

            $summary = is_array($lockedReport->summary) ? $lockedReport->summary : [];
            $previous = $summary['rollback_execution'] ?? null;
            $resolved = [];
            if ($version === 2 && is_array($previous)) {
                $resolved = $previous['resolved_indices'] ?? null;
                $signature = $previous['resolved_signature'] ?? null;
                if (! is_array($resolved) || ! array_is_list($resolved) || ! is_string($signature) || ! RollbackProvenance::hasValidSignature(['report_uuid' => $lockedReport->uuid, 'resolved_indices' => $resolved], $signature)) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
                }

                $seenIndices = [];
                foreach ($resolved as $resolvedIndex) {
                    if (! is_int($resolvedIndex) || ! array_key_exists($resolvedIndex, $entries) || isset($seenIndices[$resolvedIndex])) {
                        return $this->rejectedResult($lockedReport, $actor, $dryRun, 'invalid_provenance');
                    }

                    $seenIndices[$resolvedIndex] = true;
                }
            }

            $matched = 0;
            $skipped = [];
            $deletable = [];
            $missing = [];
            $pairs = [];
            $softDeleted = [];

            foreach (array_reverse($entries, true) as $index => $entry) {
                if ($version === 2 && in_array($index, $resolved, true)) {
                    continue;
                }

                if (($entry['type'] ?? null) === RollbackProvenance::ROLE_PAIR) {
                    if (! $this->rolePairRow($entry, lock: true)) {
                        $missing[] = [$index, null];
                        $skipped[] = $this->skip($entry, 'missing');

                        continue;
                    }

                    $matched++;
                    $pairs[] = [$entry, $index];

                    continue;
                }

                $targetClass = null;
                if ($plan instanceof DatabaseImportWritePlan) {
                    if ($plan->executionMode === 'dedicated-process') {
                        $entryMapping = $entry['mapping'] ?? null;
                        $plannedClass = is_array($entryMapping) ? ($entryMapping['target_class'] ?? null) : null;
                        if (! is_string($plannedClass) || ! isset($plan->targets[$plannedClass])) {
                            throw InPlaceDatabaseRefusalReason::Target->exception('The signed rollback mapping is outside its original target plan.');
                        }

                        $targetClass = $plannedClass;
                    } else {
                        $targetClass = array_key_first($plan->targets);
                    }
                }

                $model = RollbackProvenance::findEntryModel($entry, lockForUpdate: true, connection: $plan?->writerConnection, targetClass: $targetClass);

                if (! $model instanceof Model) {
                    if ($version === 2) {
                        $mapping = $entry['mapping'];
                        if (is_array($mapping)) {
                            $currentMapping = $connection->table(TableName::resolve('import_source_keys'))->where('id', $mapping['id'])->lockForUpdate()->first();
                            if ($currentMapping !== null && ($currentMapping->target_class !== $mapping['target_class'] || $currentMapping->target_id !== $mapping['target_id'] || $currentMapping->source_checksum !== $mapping['source_checksum'] || $currentMapping->target_checksum !== $mapping['target_checksum'])) {
                                $skipped[] = $this->skip($entry, 'mapping_changed');

                                continue;
                            }
                        }

                        $missing[] = [$index, is_array($mapping) ? $mapping['id'] : null];
                    }

                    $skipped[] = $this->skip($entry, 'missing');

                    continue;
                }

                $plan?->assertTarget($model);
                $matched++;
                $reason = RollbackProvenance::matchesEntry($model, $entry, $plan instanceof DatabaseImportWritePlan && ($plan->executionMode !== 'dedicated-process' || $model instanceof SettingsProperty) ? 'setting' : null);

                if ($reason === null && $version === 2 && (! is_string($entry['after_hash'] ?? null) || ! hash_equals($entry['after_hash'], hash('sha256', json_encode($model->getAttributes(), JSON_THROW_ON_ERROR))))) {
                    $reason = 'edited_after_import';
                }

                if ($reason !== null) {
                    $skipped[] = $this->skip($entry, $reason);

                    continue;
                }

                $mapping = $version === 2 ? $entry['mapping'] : null;
                if (is_array($mapping)) {
                    $currentMapping = $connection->table(TableName::resolve('import_source_keys'))->where('id', $mapping['id'])->lockForUpdate()->first();
                    if ($currentMapping === null || $currentMapping->target_class !== $model::class || $currentMapping->target_id !== $mapping['target_id'] || $currentMapping->target_class !== $mapping['target_class'] || $currentMapping->target_id !== $mapping['target_id'] || $currentMapping->source_checksum !== $mapping['source_checksum'] || $currentMapping->target_checksum !== $mapping['target_checksum']) {
                        $skipped[] = $this->skip($entry, 'mapping_changed');

                        continue;
                    }
                }

                $operation = $version === 2 ? $entry['operation'] : 'created';
                if (! Gate::forUser($actor)->allows($operation === 'updated' ? 'update' : 'delete', $model)) {
                    return $this->rejectedResult($lockedReport, $actor, $dryRun, 'unauthorized');
                }

                $deletable[] = [$model, $operation, $version === 2 ? $images[$index] : null, $mapping, $index];
            }

            $deleted = 0;
            $restored = 0;

            if (! $dryRun) {
                foreach ($missing as [$index, $mappingId]) {
                    if ($mappingId !== null) {
                        $connection->table(TableName::resolve('import_source_keys'))->where('id', $mappingId)->delete();
                    }

                    $resolved[] = $index;
                }

                foreach ($pairs as [$entry, $index]) {
                    $this->rolePairQuery($entry)->delete();
                    $deleted++;
                    $resolved[] = $index;
                }

                if ($pairs !== [] && class_exists(PermissionRegistrar::class)) {
                    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
                }

                foreach ($deletable as [$model, $operation, $image, $mapping, $index]) {
                    if ($plan instanceof DatabaseImportWritePlan) {
                        resolve(FinalisePlannedContentGraphAction::class)->handle($plan, $model);
                    }

                    if ($operation === 'updated') {
                        $imageKey = is_array($image) ? ($image[$model->getKeyName()] ?? null) : null;
                        $modelKey = $model->getKey();
                        if ((! is_int($imageKey) && ! is_string($imageKey)) || (! is_int($modelKey) && ! is_string($modelKey)) || (string) $imageKey !== (string) $modelKey) {
                            throw new UnexpectedValueException('Invalid rollback before-image.');
                        }

                        $model->setRawAttributes($image);
                        $model->timestamps = false;
                        if (! $model->save()) {
                            throw new UnexpectedValueException('Rollback target could not be restored.');
                        }

                        $restored++;
                    } else {
                        // A row the import created is purged, not soft-deleted, so its unique keys are free for a re-import.
                        $soft = in_array(SoftDeletes::class, class_uses_recursive($model), true);
                        $blocker = $soft ? RollbackPurgeGuard::blockingReference($model) : null;
                        if ($blocker !== null) {
                            // Rows added after the import reference this one; a purge would cascade into them.
                            $softDeleted[] = ['type' => RollbackProvenance::entryFor($model)['type'], 'id' => $this->scalarKey($model), 'reason' => 'has_references:' . $blocker];
                        }

                        $removed = $soft && $blocker === null ? $model->forceDelete() : $model->delete();
                        if ($removed !== true) {
                            throw new UnexpectedValueException('Rollback target could not be deleted.');
                        }

                        $deleted++;
                    }

                    if ($version === 2) {
                        $resolved[] = $index;
                    }

                    if (is_array($mapping)) {
                        if ($operation === 'created') {
                            $connection->table(TableName::resolve('import_source_keys'))->where('id', $mapping['id'])->delete();
                        } else {
                            $connection->table(TableName::resolve('import_source_keys'))->where('id', $mapping['id'])->update([
                                'source_checksum' => $mapping['previous_source_checksum'],
                                'target_checksum' => $mapping['previous_target_checksum'],
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                sort($resolved);
                $summary['rollback_execution'] = [
                    'deleted' => $deleted,
                    'restored' => $restored,
                    'executed_at' => now()->toISOString(),
                    'matched' => $matched,
                    'resolved' => $version === 2 ? count($resolved) : count($deletable),
                    'resolved_indices' => $resolved,
                    'resolved_signature' => RollbackProvenance::sign(['report_uuid' => $lockedReport->uuid, 'resolved_indices' => $resolved]),
                    'skipped' => $skipped,
                    'soft_deleted' => $softDeleted,
                ];

                $lockedReport->forceFill(['summary' => $summary])->save();
            }

            $result = new RollbackExecutionResultData(
                matched: $matched,
                deleted: $deleted,
                skipped: $skipped,
                dryRun: $dryRun,
                restored: $restored,
            );

            $this->audit($lockedReport, $actor, $result, $skipped === [] ? 'completed' : 'completed_with_skips');
            $siteIds = array_values(array_unique(array_filter(array_column($entries, 'site_id'), is_int(...))));

            return $result;
        });
        if (! $dryRun && ! $result->rejected && ($result->deleted + $result->restored) > 0) {
            // Removing or restoring rows quietly leaves the public HTML cache serving the imported pages.
            InvalidateRollbackCachesAction::run($siteIds);
        }

        return $result;
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
                ...(array_key_exists('write_plan', $provenance) ? ['write_plan'] : []),
            ])
            && in_array($provenance['version'] ?? null, [1, 2], true)
            && ($provenance['report_uuid'] ?? null) === $report->uuid
            && ($provenance['session_uuid'] ?? null) === $session->uuid
            && ($provenance['session_user_id'] ?? null) === $session->user_id
            && is_string($provenance['executed_at'] ?? null)
            && RollbackProvenance::hasValidSignature($provenance, $report->provenance_signature);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function isValidEntry(array $entry, int $version): bool
    {
        $type = $entry['type'] ?? null;
        if ($type === RollbackProvenance::ROLE_PAIR) {
            return $version === 2 && $this->isValidRolePairEntry($entry);
        }

        return $this->hasExactKeys($entry, $version === 2 ? ['after_hash', 'before_hash', 'created_at', 'id', 'mapping', 'operation', 'site_id', 'type', 'updated_at'] : ['created_at', 'id', 'site_id', 'type', 'updated_at'])
            && ($version !== 2 || ($this->validMapping($entry['mapping'] ?? null, $entry['operation'] ?? null) && in_array($entry['operation'] ?? null, ['created', 'updated'], true) && is_string($entry['after_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $entry['after_hash']) === 1 && is_string($entry['before_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $entry['before_hash']) === 1))
            && is_string($type)
            && RollbackProvenance::isAllowedType($type)
            && (is_int($entry['id'] ?? null) || is_string($entry['id'] ?? null))
            && (is_int($entry['site_id'] ?? null) || ($version === 2 && array_key_exists('site_id', $entry) && $entry['site_id'] === null))
            && is_string($entry['created_at'] ?? null)
            && is_string($entry['updated_at'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function isValidRolePairEntry(array $entry): bool
    {
        $roleId = $entry['role_id'] ?? null;
        $modelType = $entry['model_type'] ?? null;
        $modelId = $entry['model_id'] ?? null;

        $teamId = $entry['team_id'] ?? null;

        return $this->hasExactKeys($entry, ['after_hash', 'before_hash', 'id', 'mapping', 'model_id', 'model_type', 'operation', 'role_id', 'site_id', 'team_id', 'type'])
            && (is_int($roleId) || is_string($roleId)) && is_string($modelType) && $modelType !== '' && (is_int($modelId) || is_string($modelId))
            && ($teamId === null || is_int($teamId) || is_string($teamId))
            && $entry === RollbackProvenance::rolePairEntry($roleId, $modelType, $modelId, $teamId);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private function rolePairRow(array $entry, bool $lock): bool
    {
        RollbackProvenance::rolePairColumns();
        $query = $this->rolePairQuery($entry);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /**
     * The pivot rows of one journalled assignment. With teams enabled, or an assignment journalled under a team, the
     * team key is part of the identity: the same role and user may hold another team's assignment that must stay.
     *
     * @param  array<array-key, mixed>  $entry
     */
    private function rolePairQuery(array $entry): Builder
    {
        $columns = RollbackProvenance::rolePairColumns();
        $query = DB::table($columns['table'])->where($columns['role'], $entry['role_id'])->where('model_type', $entry['model_type'])->where($columns['model'], $entry['model_id']);
        $teamColumn = $columns['team'];
        if ($teamColumn === null && ($entry['team_id'] ?? null) !== null) {
            $teamColumn = is_string(config('permission.column_names.team_foreign_key')) ? config('permission.column_names.team_foreign_key') : 'team_id';
        }

        if ($teamColumn !== null) {
            ($entry['team_id'] ?? null) === null ? $query->whereNull($teamColumn) : $query->where($teamColumn, $entry['team_id']);
        }

        return $query;
    }

    private function validMapping(mixed $mapping, mixed $operation): bool
    {
        if ($mapping === null) {
            return true;
        }

        if (! is_array($mapping) || ! $this->hasExactKeys($mapping, ['id', 'target_class', 'target_id', 'source_checksum', 'target_checksum', 'previous_source_checksum', 'previous_target_checksum'])) {
            return false;
        }

        if ($operation === 'created' && ($mapping['previous_source_checksum'] !== null || $mapping['previous_target_checksum'] !== null)) {
            return false;
        }

        if ($operation === 'updated' && (! is_string($mapping['previous_source_checksum']) || ! is_string($mapping['previous_target_checksum']))) {
            return false;
        }

        return is_int($mapping['id']) && is_string($mapping['target_class']) && is_string($mapping['target_id'])
            && is_string($mapping['source_checksum']) && is_string($mapping['target_checksum'])
            && (is_string($mapping['previous_source_checksum']) || $mapping['previous_source_checksum'] === null)
            && (is_string($mapping['previous_target_checksum']) || $mapping['previous_target_checksum'] === null);
    }

    /**
     * @param  list<array<array-key, mixed>>  $entries
     */
    private function actorCanRollbackEntries(?Authenticatable $actor, array $entries): bool
    {
        if (! $actor instanceof Authenticatable || ! $this->actorIsActive($actor)) {
            return false;
        }

        if ($entries === []) {
            return $this->isGlobalActor($actor) && $this->hasGlobalRollbackPermission($actor);
        }

        if (array_filter($entries, static fn (array $entry): bool => $entry['site_id'] === null) !== [] && (! $this->isGlobalActor($actor) || ! $this->hasGlobalRollbackPermission($actor))) {
            return false;
        }

        $siteIds = array_values(array_unique(array_filter(array_column($entries, 'site_id'), is_int(...))));

        return array_all($siteIds, fn (int $siteId): bool => $this->actorCanRollbackSite($actor, $siteId));
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

            if (! $assignedSiteIds->contains($siteId)) {
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
        $type = $entry['type'] ?? null;
        $id = $entry['id'] ?? null;

        if (! is_string($type) || (! is_int($id) && ! is_string($id))) {
            throw new UnexpectedValueException('Expected a validated rollback entry.');
        }

        return [
            'type' => $type,
            'id' => $id,
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
            skipped: [['type' => 'report', 'id' => $this->scalarKey($report), 'reason' => $reason]],
            dryRun: $dryRun,
            rejected: true,
        );

        $this->audit($report, $actor, $result, 'rejected');

        return $result;
    }

    private function scalarKey(Model $model): int|string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new UnexpectedValueException('Expected a scalar model key.');
        }

        return $key;
    }

    private function audit(
        ImportRollbackReport $report,
        ?Authenticatable $actor,
        RollbackExecutionResultData $result,
        string $outcome,
    ): void {
        $actorId = $actor?->getAuthIdentifier();

        ImportRollbackAudit::on($report->getConnectionName())->create([
            'import_rollback_report_id' => $report->getKey(),
            'actor_id' => is_int($actorId) ? $actorId : null,
            'dry_run' => $result->dryRun,
            'outcome' => $outcome,
            'matched' => $result->matched,
            'deleted' => $result->deleted,
            'restored' => $result->restored,
            'skipped' => $result->skipped,
        ]);
    }
}
