<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\DatabaseRollbackChange;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ImportRollbackReport run(ImportSession $session, list<DatabaseRollbackChange> $changes, list<array{role_id: int|string, model_type: string, model_id: int|string, team_id?: int|string|null}> $rolePairs = []) */
final class CreateDatabaseRollbackReportAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<DatabaseRollbackChange>  $changes
     * @param  list<array{role_id: int|string, model_type: string, model_id: int|string, team_id?: int|string|null}>  $rolePairs  role assignments the import created
     */
    public function handle(ImportSession $session, array $changes, array $rolePairs = []): ImportRollbackReport
    {
        $uuid = (string) Str::uuid();
        $entries = [];
        $images = [];
        $seen = [];
        $counts = [];
        foreach ($changes as $change) {
            $model = $change->model;
            if (! $model->exists) {
                throw new LogicException('Rollback target must exist.');
            }
            $key = $model->getKey();
            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException('Rollback target requires a scalar key.');
            }
            $model = $model->newQueryWithoutScopes()->whereKey($key)->firstOrFail();
            $identity = $model::class . ':' . (string) $key;
            if (isset($seen[$identity])) {
                throw new LogicException('Duplicate rollback target.');
            }
            $seen[$identity] = true;
            if ($change->source === null && DB::table('import_source_keys')->where('target_class', $model::class)->where('target_id', (string) $key)->exists()) {
                throw new LogicException('Rollback source mapping is required.');
            }
            if ($change->beforeImage === null && $change->source === null) {
                throw new LogicException('Rollback created target requires source evidence.');
            }
            $entry = RollbackProvenance::entryFor($model);
            if ($change->source !== null && ((string) $change->source->targetId !== (string) $key || $change->source->outcome !== ($change->beforeImage === null ? 'created' : 'updated') || $change->source->mapping === null || $change->source->beforeImage !== $change->beforeImage)) {
                throw new LogicException('Rollback source mapping does not match the target.');
            }
            if ($change->source !== null) {
                $mapping = $change->source->mapping;
                $siteScope = $session->target_type === 'site' ? 'site:' . $session->target_id : 'global';
                if (! is_array($mapping) || ! is_int($mapping['id'] ?? null) || ($mapping['target_class'] ?? null) !== $model::class || ($mapping['target_id'] ?? null) !== (string) $key || ! is_string($mapping['source_checksum'] ?? null) || ! is_string($mapping['target_checksum'] ?? null) || ! DB::table('import_source_keys')->where('id', $mapping['id'])->where('site_scope', $siteScope)
                    ->where('source_environment', $session->source_environment)->where('target_class', $model::class)->where('target_id', (string) $key)
                    ->where('source_checksum', $mapping['source_checksum'])->where('target_checksum', $mapping['target_checksum'])->exists()) {
                    throw new LogicException('Rollback source mapping does not match the session.');
                }
            }
            $entry['mapping'] = $change->source?->mapping;
            $entry['after_hash'] = hash('sha256', json_encode($model->getAttributes(), JSON_THROW_ON_ERROR));
            $entry['operation'] = $change->beforeImage === null ? 'created' : 'updated';
            $type = $entry['type'];
            $counts[$type] ??= ['created' => 0, 'updated' => 0];
            $counts[$type][$entry['operation']]++;
            $images[] = $change->beforeImage;
            $entry['before_hash'] = hash('sha256', json_encode($change->beforeImage, JSON_THROW_ON_ERROR));
            $entries[] = $entry;
        }
        // Role assignments come last so a rollback, which walks the entries in reverse, removes them before the
        // users and roles they connect.
        foreach ($rolePairs as $pair) {
            $entry = RollbackProvenance::rolePairEntry($pair['role_id'], $pair['model_type'], $pair['model_id'], $pair['team_id'] ?? null);
            if (isset($seen[$entry['id']])) {
                throw new LogicException('Duplicate rollback target.');
            }
            $seen[$entry['id']] = true;
            $counts[RollbackProvenance::ROLE_PAIR]['created'] = ($counts[RollbackProvenance::ROLE_PAIR]['created'] ?? 0) + 1;
            $counts[RollbackProvenance::ROLE_PAIR]['updated'] ??= 0;
            $images[] = null;
            $entries[] = $entry;
        }
        $provenance = [
            'version' => 2,
            'report_uuid' => $uuid,
            'session_uuid' => $session->uuid,
            'session_user_id' => $session->user_id,
            'executed_at' => ($session->executed_at ?? now())->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
            'entries' => $entries,
        ];
        $report = new ImportRollbackReport([
            'uuid' => $uuid,
            'import_session_id' => $session->getKey(),
            'user_id' => $session->user_id,
            'created_models' => [],
            'summary' => ['entities' => $counts],
            'manual_instructions' => (string) __('migration-assistant::rollback.manual'),
            'executed_at' => $session->executed_at ?? now(),
        ]);
        $report->forceFill([
            'before_images' => $images,
            'provenance' => $provenance,
            'provenance_signature' => RollbackProvenance::sign($provenance),
        ])->save();

        return $report;
    }
}
