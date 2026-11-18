<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ImportRollbackReport run(ImportSession $session, ImportExecutionReport $report, bool $merge = false)
 */
final class CreateImportRollbackReportAction
{
    use AsFake;
    use AsObject;

    public function handle(ImportSession $session, ImportExecutionReport $report, bool $merge = false): ImportRollbackReport
    {
        $existing = $merge ? $session->rollbackReports()->latest('id')->first() : null;
        if ($existing instanceof ImportRollbackReport) {
            $models = [];
            foreach ($report->createdModels() as $created) {
                $modelClass = $created['class'];
                throw_unless(is_subclass_of($modelClass, Model::class), LogicException::class, 'Rollback targets must be Eloquent models.');
                $model = $modelClass::query()->withoutGlobalScopes()->find($created['id']);
                if ($model instanceof Model) {
                    $models[] = $model;
                }
            }
            $existing = AppendImportRollbackModelsAction::run($existing, $models);
            $existing->forceFill(['summary' => array_replace($existing->summary ?? [], $report->toArray())])->save();

            return $existing;
        }

        $uuid = (string) Str::uuid();
        $provenance = RollbackProvenance::create($uuid, $session, $report);

        $rollbackReport = new ImportRollbackReport([
            'uuid' => $uuid,
            'import_session_id' => $session->getKey(),
            'user_id' => $session->user_id,
            'source_filename' => $session->source_filename,
            'source_package_checksum' => $session->source_package_checksum,
            'created_models' => $report->createdModels(),
            'summary' => $report->toArray(),
            'manual_instructions' => $this->instructionsFor($report),
            'executed_at' => $session->executed_at ?? now(),
        ]);

        $rollbackReport->forceFill([
            'provenance' => $provenance,
            'provenance_signature' => RollbackProvenance::sign($provenance),
        ])->save();

        return $rollbackReport;
    }

    private function instructionsFor(ImportExecutionReport $report): string
    {
        if ($report->createdModels() === []) {
            return (string) __('migration-assistant::rollback.none_created');
        }

        return (string) __('migration-assistant::rollback.manual');
    }
}
