<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SetImportLegalHoldAction
{
    use AsFake;
    use AsObject;

    public function handle(ImportSession $session, bool $hold): ImportSession
    {
        Gate::authorize('view', $session);
        Gate::authorize(MigrationAssistantPermission::ImportSessionLegalHold->value);

        return DB::transaction(function () use ($session, $hold): ImportSession {
            $locked = ImportSession::query()->lockForUpdate()->findOrFail($session->id);
            // Holding or releasing evidence must not reset its retention clock.
            $locked->timestamps = false;
            $locked->forceFill(['legal_hold' => $hold])->save();

            return $locked;
        });
    }
}
