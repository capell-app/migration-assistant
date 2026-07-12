<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\ImportRecoveryStatusData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static ImportRecoveryStatusData run() */
final class BuildImportRecoveryStatusAction
{
    use AsObject;

    public function handle(): ImportRecoveryStatusData
    {
        $configuredMinutes = config('migration-assistant.recovery.stale_after_minutes', 30);
        $staleAfterMinutes = is_numeric($configuredMinutes) ? max(20, (int) $configuredMinutes) : 30;
        $query = ImportSession::query()
            ->where('status', ImportSessionStatus::Running->value)
            ->where('updated_at', '<=', now()->subMinutes($staleAfterMinutes));
        $oldest = (clone $query)->oldest('updated_at')->first(['updated_at']);

        return new ImportRecoveryStatusData(
            staleCount: (clone $query)->count(),
            oldestAgeMinutes: $oldest?->updated_at !== null ? (int) $oldest->updated_at->diffInMinutes(now()) : null,
        );
    }
}
