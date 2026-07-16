<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ImportSession|null run(?int $sessionId)
 */
final class ResolvePageImportSessionAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $sessionId): ?ImportSession
    {
        if ($sessionId === null) {
            return null;
        }

        $query = ImportSession::query()
            ->whereKey($sessionId)
            ->whereIn('kind', [
                ImportSessionKind::PageImport,
                ImportSessionKind::SiteImport,
            ]);

        if (auth()->id() !== null) {
            $query->where('user_id', auth()->id());
        } elseif (app()->runningInConsole()) {
            $query->whereNull('user_id');
        } else {
            return null;
        }

        $session = $query->first();

        return $session instanceof ImportSession ? $session : null;
    }
}
