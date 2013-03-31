<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use RuntimeException;

final class PrepareMigrationScreenshotDetailAction
{
    public function handle(): ImportSession
    {
        throw_unless(app()->environment('local', 'testing') && getenv('CAPELL_SCREENSHOT_FIXTURE') === 'record-state', RuntimeException::class, 'Migration screenshot fixtures require the disposable screenshot environment.');

        return (new ImportSession)->getConnection()->transaction(static function (): ImportSession {
            $session = ImportSession::query()->updateOrCreate([
                'uuid' => 'fe000000-0000-4000-9000-000000000001',
            ], [
                'user_id' => auth()->id(),
                'kind' => ImportSessionKind::PageImport,
                'status' => ImportSessionStatus::Completed,
                'source_filename' => 'screenshot-reviewed-pages.zip',
                'validation_results' => [
                    'pages' => ['create' => 0, 'update' => 0, 'skip' => 2],
                    'relations' => ['match' => 2, 'create' => 0],
                    'warnings' => ['Two existing pages were deliberately skipped.'],
                ],
                'relation_decisions' => ['homepage' => ['action' => 'match', 'notes' => 'Keep the existing homepage relation.']],
                'result_summary' => ['pages_imported' => 0, 'pages_skipped' => 2],
                'executed_at' => now()->subHour(),
            ]);

            if (! $session->rollbackReports()->exists()) {
                CreateImportRollbackReportAction::run($session, new ImportExecutionReport(0, 2, [], []));
            }

            return $session;
        });
    }
}
