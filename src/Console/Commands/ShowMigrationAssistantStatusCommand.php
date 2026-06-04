<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ShowMigrationAssistantStatusCommand extends Command
{
    protected $signature = 'migration-assistant:status
        {session? : Import session id or UUID}
        {--json : Output status data as JSON}';

    protected $description = 'Show Migration Assistant import session status.';

    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.status.description');
    }

    public function handle(): int
    {
        $sessionIdentifier = $this->argument('session');

        if (is_string($sessionIdentifier) && $sessionIdentifier !== '') {
            $session = $this->findSession($sessionIdentifier);

            if (! $session instanceof ImportSession) {
                $this->components->error((string) __('migration-assistant::commands.status.not_found', [
                    'session' => $sessionIdentifier,
                ]));

                return SymfonyCommand::FAILURE;
            }

            $rows = [$this->rowForSession($session)];
        } else {
            $rows = ImportSession::query()
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (ImportSession $session): array => $this->rowForSession($session))
                ->all();
        }

        if ((bool) $this->option('json')) {
            $this->output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return SymfonyCommand::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info((string) __('migration-assistant::commands.status.empty'));

            return SymfonyCommand::SUCCESS;
        }

        $this->table([
            (string) __('migration-assistant::commands.status.columns.id'),
            (string) __('migration-assistant::commands.status.columns.uuid'),
            (string) __('migration-assistant::commands.status.columns.kind'),
            (string) __('migration-assistant::commands.status.columns.status'),
            (string) __('migration-assistant::commands.status.columns.source'),
            (string) __('migration-assistant::commands.status.columns.executed_at'),
        ], $rows);

        return SymfonyCommand::SUCCESS;
    }

    private function findSession(string $sessionIdentifier): ?ImportSession
    {
        return ImportSession::query()
            ->where('uuid', $sessionIdentifier)
            ->orWhere((new ImportSession)->getKeyName(), is_numeric($sessionIdentifier) ? (int) $sessionIdentifier : 0)
            ->first();
    }

    /**
     * @return array{id: int|string|null, uuid: string, kind: string, status: string, source: string|null, executed_at: string|null}
     */
    private function rowForSession(ImportSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'uuid' => $session->uuid,
            'kind' => $session->kind->value,
            'status' => $session->status->value,
            'source' => $session->source_filename,
            'executed_at' => $session->executed_at?->toIso8601String(),
        ];
    }
}
