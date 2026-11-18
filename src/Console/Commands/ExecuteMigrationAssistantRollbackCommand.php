<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Actions\RunDedicatedImportProcessAction;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\SafeFailureCause;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Override;
use Symfony\Component\Process\Process;

final class ExecuteMigrationAssistantRollbackCommand extends Command
{
    protected $signature = 'migration-assistant:rollback-execute
        {session : Import session ID or UUID}
        {--actor= : Authenticated user ID to authorize and audit the rollback}
        {--dry-run : Report what would be deleted without deleting records}
        {--dedicated : Roll back a dedicated-process import in a child process limited to its recorded profiles}
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

        if (! $actor instanceof Authenticatable) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.actor_required'));

            return self::FAILURE;
        }

        if ((bool) $this->option('dedicated')) {
            return $this->runDedicated($report, $sessionIdentifier);
        }

        $result = ExecuteImportRollbackAction::run($report, actor: $actor, dryRun: (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
        }

        if ($result->rejected) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.rejected'));

            return self::FAILURE;
        }

        $this->components->info((string) __('migration-assistant::commands.rollback_execute.summary', [
            'deleted' => $result->deleted,
            'restored' => $result->restored,
            'matched' => $result->matched,
            'skipped' => count($result->skipped),
        ]));

        if ($result->dryRun) {
            $this->components->warn((string) __('migration-assistant::commands.rollback_execute.dry_run'));
        }

        if ($result->hasFailures()) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.delete_failed'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** The child re-authorises the actor and re-checks the report; this process only chooses its recorded profiles. */
    private function runDedicated(ImportRollbackReport $report, string $sessionIdentifier): int
    {
        $plan = data_get($report->provenance, 'write_plan');
        $names = [data_get($plan, 'logical_connection'), data_get($plan, 'reader_connection'), data_get($plan, 'writer_connection')];
        if (data_get($plan, 'execution_mode') !== 'dedicated-process' || array_filter($names, is_string(...)) !== $names) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.not_dedicated'));

            return self::FAILURE;
        }

        // The resolved UUID, never the operator's text, so the child cannot read the identifier as an option.
        $session = $report->importSession;
        if (! $session instanceof ImportSession) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.not_found', ['session' => $sessionIdentifier]));

            return self::FAILURE;
        }

        $arguments = ['migration-assistant:rollback-execute', (string) $session->uuid, '--no-interaction'];
        $actor = $this->option('actor');
        if (is_string($actor) && $actor !== '') {
            $arguments[] = '--actor=' . $actor;
        }

        foreach (['dry-run', 'json'] as $flag) {
            if ((bool) $this->option($flag)) {
                $arguments[] = '--' . $flag;
            }
        }

        [$inspector, $reader, $writer] = $names;
        try {
            return resolve(RunDedicatedImportProcessAction::class)->handle($inspector, $reader, $writer, $arguments, function (string $type, string $buffer): void {
                $type === Process::ERR ? $this->getOutput()->getErrorStyle()->write($buffer) : $this->getOutput()->write($buffer);
            });
        } catch (ImportRefusedException $exception) {
            $this->components->error((string) __('migration-assistant::commands.rollback_execute.dedicated_refused', ['cause' => SafeFailureCause::describe($exception)]));

            return self::FAILURE;
        }
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

    private function actor(): ?Authenticatable
    {
        $actorId = $this->option('actor');

        if (! is_numeric($actorId)) {
            return null;
        }

        $actor = Auth::guard()->getProvider()->retrieveById((int) $actorId);

        return $actor instanceof Authenticatable ? $actor : null;
    }
}
