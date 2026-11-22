<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Support\Str;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Brings the session's rollback provenance up to date with the import's own final writes.
 *
 * Importers record provenance as soon as a model exists, so a crash part-way leaves it removable, and then keep
 * writing the same models (parent links, media, authors, baselines) before the session completes. Each of those
 * saves moves updated_at, and a save that lands in a later second than the recorded entry would make the import's
 * own pages look edited after import and block their rollback. The importer calls this once, after its last write
 * and before it marks the session finished; from then on provenance is never refreshed, so a later editorial edit
 * still blocks deletion.
 *
 * @method static ImportRollbackReport|null run(ImportSession $session)
 */
final class SealImportRollbackProvenanceAction
{
    use AsFake;
    use AsObject;

    public function handle(ImportSession $session): ?ImportRollbackReport
    {
        $report = $session->rollbackReports()->latest('id')->first();

        if (! $report instanceof ImportRollbackReport) {
            return null;
        }

        return $report->getConnection()->transaction(static function () use ($report): ImportRollbackReport {
            /** @var ImportRollbackReport $locked */
            $locked = $report->newQuery()->lockForUpdate()->findOrFail($report->getKey());
            $provenance = $locked->provenance;
            throw_unless(is_array($provenance) && ($provenance['version'] ?? null) === 1 && RollbackProvenance::hasValidSignature($provenance, $locked->provenance_signature), RuntimeException::class, 'Cannot seal invalid rollback provenance.');

            $entries = is_array($provenance['entries'] ?? null) ? $provenance['entries'] : [];
            $changed = false;

            foreach ($entries as $index => $entry) {
                if (! is_array($entry) || ($entry['type'] ?? null) === RollbackProvenance::ROLE_PAIR) {
                    continue;
                }

                $model = RollbackProvenance::findEntryModel($entry);

                if ($model === null) {
                    continue;
                }

                try {
                    $current = RollbackProvenance::entryFor($model, is_string($entry['type'] ?? null) ? $entry['type'] : null);
                } catch (LogicException) {
                    continue;
                }

                // Only the import's own timestamp drift is absorbed. A different target, site or creation time is
                // left for the rollback to reject.
                if ($current['id'] !== ($entry['id'] ?? null) || $current['site_id'] !== ($entry['site_id'] ?? null) || $current['created_at'] !== ($entry['created_at'] ?? null)) {
                    continue;
                }

                $sealed = [...$entry, 'updated_at' => $current['updated_at']];

                if (array_key_exists('attributes_hash', $entry)) {
                    $sealed['attributes_hash'] = RollbackProvenance::attributesHash($model);
                }

                if ($sealed !== $entry) {
                    $entries[$index] = $sealed;
                    $changed = true;
                }
            }

            if (! $changed) {
                return $locked;
            }

            // Provenance is immutable. Publish a new signed report, retaining the original one.
            $next = $locked->replicate(['uuid']);
            $uuid = (string) Str::uuid();
            $provenance['report_uuid'] = $uuid;
            $provenance['entries'] = array_values($entries);
            $next->forceFill([
                'uuid' => $uuid,
                'provenance' => $provenance,
                'provenance_signature' => RollbackProvenance::sign($provenance),
            ])->save();

            return $next;
        });
    }
}
