<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static ImportRollbackReport run(ImportSession $session, ImportExecutionReport $report)
 */
final class CreateImportRollbackReportAction
{
    use AsAction;

    public function handle(ImportSession $session, ImportExecutionReport $report): ImportRollbackReport
    {
        $uuid = (string) Str::uuid();
        $provenance = RollbackProvenance::create($uuid, $session, $report);

        return ImportRollbackReport::query()->create([
            'uuid' => $uuid,
            'import_session_id' => $session->getKey(),
            'user_id' => $session->user_id,
            'source_filename' => $session->source_filename,
            'source_package_checksum' => $session->source_package_checksum,
            'created_models' => $report->createdModels(),
            'provenance' => $provenance,
            'provenance_signature' => RollbackProvenance::sign($provenance),
            'summary' => $report->toArray(),
            'manual_instructions' => $this->instructionsFor($report),
            'executed_at' => $session->executed_at ?? now(),
        ]);
    }

    private function instructionsFor(ImportExecutionReport $report): string
    {
        if ($report->createdModels() === []) {
            return (string) __('migration-assistant::rollback.none_created');
        }

        return (string) __('migration-assistant::rollback.manual');
    }
}
