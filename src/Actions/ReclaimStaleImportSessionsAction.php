<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsAction;

/** @method static int run(?int $staleAfterMinutes = null, ?int $limit = null) */
final class ReclaimStaleImportSessionsAction
{
    use AsAction;

    public function handle(?int $staleAfterMinutes = null, ?int $limit = null): int
    {
        $staleAfterMinutes ??= $this->configuredInt('recovery.stale_after_minutes', 30);
        $limit ??= $this->configuredInt('recovery.batch_limit', 100);
        $cutoff = now()->subMinutes(max(20, $staleAfterMinutes));
        $reclaimed = 0;

        $sessionIds = ImportSession::query()
            ->where('status', ImportSessionStatus::Running->value)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->pluck((new ImportSession)->getKeyName());

        foreach ($sessionIds as $sessionId) {
            $updated = ImportSession::query()
                ->whereKey($sessionId)
                ->where('status', ImportSessionStatus::Running->value)
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => ImportSessionStatus::Queued->value,
                    'failure_reason' => null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                continue;
            }

            dispatch(new ExecuteImportPlanJob((int) $sessionId));
            $reclaimed++;
        }

        return $reclaimed;
    }

    private function configuredInt(string $key, int $default): int
    {
        $value = config("migration-assistant.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
