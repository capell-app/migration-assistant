<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class ExecuteMigrationAssistantRollbackCommand extends Command
{
    protected $signature = 'migration-assistant:rollback-execute
        {session : Import session ID or UUID}
        {--dry-run : Report what would be deleted without deleting records}
        {--json : Output rollback execution summary as JSON}';

    protected $description = 'Execute a Migration Assistant rollback report by deleting recorded created models.';

    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.rollback_execute.description');
    }

    public function handle(): int
    {
        $sessionIdentifier = (string) $this->argument('session');
        $report = $this->findRollbackReport($sessionIdentifier);

        if (! $report instanceof ImportRollbackReport) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.not_found', [
                'session' => $sessionIdentifier,
            ]));

            return self::FAILURE;
        }

        $result = ExecuteImportRollbackAction::run($report, dryRun: (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info((string) __('migration-assistant::commands.rollback_execute.summary', [
            'deleted' => $result->deleted,
            'matched' => $result->matched,
            'skipped' => count($result->skipped),
        ]));

        if ($result->dryRun) {
            $this->components->warn((string) __('migration-assistant::commands.rollback_execute.dry_run'));
        }

        return self::SUCCESS;
    }

    private function findRollbackReport(string $sessionIdentifier): ?ImportRollbackReport
    {
        return ImportRollbackReport::query()
            ->whereHas('importSession', static function (Builder $query) use ($sessionIdentifier): void {
                $query
                    ->where('uuid', $sessionIdentifier)
                    ->orWhereKey(is_numeric($sessionIdentifier) ? (int) $sessionIdentifier : $sessionIdentifier);
            })
            ->latest('id')
            ->first();
    }
}
