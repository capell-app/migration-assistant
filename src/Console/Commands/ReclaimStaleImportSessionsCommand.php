<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\ReclaimStaleImportSessionsAction;
use Illuminate\Console\Command;
use Override;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ReclaimStaleImportSessionsCommand extends Command
{
    protected $signature = 'migration-assistant:reclaim-stale
        {--minutes= : Minimum running age before recovery}
        {--limit= : Maximum sessions to reclaim}';

    protected $description = 'Requeue import sessions abandoned by terminated workers.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.reclaim_stale.description');
    }

    public function handle(): int
    {
        $count = ReclaimStaleImportSessionsAction::run(
            $this->integerOption('minutes'),
            $this->integerOption('limit'),
        );

        $this->components->info((string) __('migration-assistant::commands.reclaim_stale.completed', [
            'count' => $count,
        ]));

        return SymfonyCommand::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
