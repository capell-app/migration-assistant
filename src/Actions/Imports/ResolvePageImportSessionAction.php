<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static ImportSession|null run(?int $sessionId)
 */
final class ResolvePageImportSessionAction
{
    use AsAction;

    public function handle(?int $sessionId): ?ImportSession
    {
        if ($sessionId === null || auth()->id() === null) {
            return null;
        }

        $session = ImportSession::query()
            ->whereKey($sessionId)
            ->where('kind', ImportSessionKind::PageImport)
            ->where('user_id', auth()->id())
            ->first();

        return $session instanceof ImportSession ? $session : null;
    }
}
