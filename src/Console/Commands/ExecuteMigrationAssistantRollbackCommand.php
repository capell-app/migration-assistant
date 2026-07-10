<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Override;

final class ExecuteMigrationAssistantRollbackCommand extends Command
{
    protected $signature = 'migration-assistant:rollback-execute
        {session : Import session ID or UUID}
        {--actor= : Authenticated user ID to authorize and audit the rollback}
        {--dry-run : Report what would be deleted without deleting records}
        {--json : Output rollback execution summary as JSON}';

    protected $description = 'Execute a signed Migration Assistant rollback report as an authorized actor.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.rollback_execute.description');
    }

    public function handle(): int
    {
        $sessionIdentifier = $this->stringArgument('session');
        $report = $this->findRollbackReport($sessionIdentifier);

        if (! $report instanceof ImportRollbackReport) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.not_found', [
                'session' => $sessionIdentifier,
            ]));

            return self::FAILURE;
        }

        $actor = $this->actor();

        if (! $actor instanceof User) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.actor_required'));

            return self::FAILURE;
        }

        $result = ExecuteImportRollbackAction::run($report, actor: $actor, dryRun: (bool) $this->option('dry-run'));

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
                    ->orWhere((new ImportSession)->getKeyName(), is_numeric($sessionIdentifier) ? (int) $sessionIdentifier : 0);
            })
            ->latest('id')
            ->first();
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) || is_int($value) || is_float($value)
            ? (string) $value
            : '';
    }

    private function actor(): ?User
    {
        $actorId = $this->option('actor');

        if (! is_numeric($actorId)) {
            return null;
        }

        return User::query()->find((int) $actorId);
    }
}
