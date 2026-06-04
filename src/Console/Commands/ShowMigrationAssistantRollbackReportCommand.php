<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ShowMigrationAssistantRollbackReportCommand extends Command
{
    protected $signature = 'migration-assistant:rollback-report
        {session : Import session id or UUID}
        {--json : Output rollback report data as JSON}';

    protected $description = 'Show the rollback report for a Migration Assistant import session.';

    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.rollback_report.description');
    }

    public function handle(): int
    {
        $sessionIdentifier = $this->argument('session');

        if (! is_string($sessionIdentifier) || $sessionIdentifier === '') {
            return SymfonyCommand::FAILURE;
        }

        $report = $this->findRollbackReport($sessionIdentifier);

        if (! $report instanceof ImportRollbackReport) {
            $this->components->error((string) __('migration-assistant::commands.rollback_report.not_found', [
                'session' => $sessionIdentifier,
            ]));

            return SymfonyCommand::FAILURE;
        }

        $payload = $this->payloadForReport($report);

        if ((bool) $this->option('json')) {
            $this->output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return SymfonyCommand::SUCCESS;
        }

        $this->components->info((string) $report->manual_instructions);
        $this->table([
            (string) __('migration-assistant::commands.rollback_report.columns.model'),
            (string) __('migration-assistant::commands.rollback_report.columns.id'),
        ], $payload['created_models']);

        return SymfonyCommand::SUCCESS;
    }

    private function findRollbackReport(string $sessionIdentifier): ?ImportRollbackReport
    {
        return ImportRollbackReport::query()
            ->whereHas('importSession', static function (Builder $query) use ($sessionIdentifier): void {
                $query
                    ->where('uuid', $sessionIdentifier)
                    ->orWhere((new ImportSession)->getKeyName(), is_numeric($sessionIdentifier) ? (int) $sessionIdentifier : 0);
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array{session_id: int|string|null, source_filename: string|null, created_models: list<array{class: string, id: int|string}>, summary: array<array-key, mixed>, manual_instructions: string}
     */
    private function payloadForReport(ImportRollbackReport $report): array
    {
        $createdModels = is_array($report->created_models) ? $report->created_models : [];

        return [
            'session_id' => $report->import_session_id,
            'source_filename' => $report->source_filename,
            'created_models' => array_values(array_filter(
                $createdModels,
                static fn (mixed $model): bool => is_array($model)
                    && is_string($model['class'] ?? null)
                    && (is_int($model['id'] ?? null) || is_string($model['id'] ?? null)),
            )),
            'summary' => is_array($report->summary) ? $report->summary : [],
            'manual_instructions' => $report->manual_instructions,
        ];
    }
}
