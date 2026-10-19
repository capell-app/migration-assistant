<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\PruneImportEvidenceAction;
use Illuminate\Console\Command;
use Override;

final class PruneImportEvidenceCommand extends Command
{
    protected $signature = 'migration-assistant:prune {--prune : Delete eligible evidence} {--json : Output a storage and retention report as JSON}';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('migration-assistant::retention.description');
    }

    public function handle(): int
    {
        $report = PruneImportEvidenceAction::run(prune: (bool) $this->option('prune'));
        if ($this->option('json')) {
            $this->line($report->toJson());
        } else {
            $this->components->info((string) __('migration-assistant::retention.report', [
                'mode' => $report->dryRun
                    ? __('migration-assistant::retention.preview')
                    : __('migration-assistant::retention.prune'),
                'days' => $report->retentionDays,
                'sessions' => $report->sessions,
                'bytes' => $report->archiveBytes,
                'eligible' => count($report->eligibleSessionIds),
                'eligible_bytes' => $report->eligibleBytes,
                'pruned' => $report->pruned,
                'failed' => count($report->failedSessionIds),
            ]));
            foreach ($report->eligibleSessionIds as $id) {
                $this->line((string) __('migration-assistant::retention.eligible', ['id' => $id]));
            }

            foreach ($report->failedSessionIds as $id) {
                $this->components->error((string) __('migration-assistant::retention.failed', ['id' => $id]));
            }
        }

        return $report->failedSessionIds === [] ? self::SUCCESS : self::FAILURE;
    }
}
