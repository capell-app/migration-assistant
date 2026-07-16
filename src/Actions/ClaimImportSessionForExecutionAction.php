<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ClaimImportSessionForExecutionAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<ImportSessionStatus>  $allowedStatuses
     */
    public function handle(ImportSession $session, ImportSessionStatus $targetStatus, array $allowedStatuses): ?ImportSession
    {
        $updated = ImportSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', array_map(
                static fn (ImportSessionStatus $status): string => $status->value,
                $allowedStatuses,
            ))
            ->update([
                'status' => $targetStatus->value,
                'failure_reason' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return null;
        }

        return $session->refresh();
    }
}
