<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static ImportRollbackReport run(ImportRollbackReport $report, iterable<Model> $models) */
final class AppendImportRollbackModelsAction
{
    use AsFake;
    use AsObject;

    /** @param iterable<Model> $models */
    public function handle(ImportRollbackReport $report, iterable $models): ImportRollbackReport
    {
        return $report->getConnection()->transaction(static function () use ($report, $models): ImportRollbackReport {
            /** @var ImportRollbackReport $locked */
            $locked = $report->newQuery()->lockForUpdate()->findOrFail($report->getKey());
            $provenance = $locked->provenance;
            throw_unless(is_array($provenance) && ($provenance['version'] ?? null) === 1 && RollbackProvenance::hasValidSignature($provenance, $locked->provenance_signature), RuntimeException::class, 'Cannot extend invalid rollback provenance.');
            $entries = is_array($provenance['entries'] ?? null) ? $provenance['entries'] : [];
            $createdModels = is_array($locked->created_models) ? $locked->created_models : [];
            $appended = false;
            foreach ($models as $model) {
                $entry = RollbackProvenance::entryFor($model);
                $entry['attributes_hash'] = RollbackProvenance::attributesHash($model);
                if (array_any($entries, static fn (mixed $existing): bool => is_array($existing) && $existing['type'] === $entry['type'] && $existing['id'] === $entry['id'])) {
                    // Never refresh a snapshot: an intervening editorial edit must still block deletion.
                    continue;
                }
                $appended = true;
                $entries[] = $entry;
                $createdModels[] = ['class' => $model::class, 'id' => $model->getKey()];
            }
            if (! $appended) {
                return $locked;
            }
            // Provenance is immutable. Publish a new signed superset, retaining the original report.
            $next = $locked->replicate(['uuid']);
            $uuid = (string) Str::uuid();
            $provenance['report_uuid'] = $uuid;
            $provenance['entries'] = $entries;
            $next->forceFill([
                'uuid' => $uuid,
                'created_models' => $createdModels,
                'provenance' => $provenance,
                'provenance_signature' => RollbackProvenance::sign($provenance),
            ])->save();

            return $next;
        });
    }
}
