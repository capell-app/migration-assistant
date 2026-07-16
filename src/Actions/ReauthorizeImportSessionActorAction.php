<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Exceptions\ImportExecutionAuthorizationException;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReauthorizeImportSessionActorAction
{
    use AsFake;
    use AsObject;

    public function handle(ImportSession $session): Authenticatable
    {
        if ($session->user_id === null) {
            throw $this->denied('execution_actor_missing');
        }

        $actor = Auth::guard()->getProvider()->retrieveById($session->user_id);

        if (! $actor instanceof Authenticatable) {
            throw $this->denied('execution_actor_missing');
        }

        if (method_exists($actor, 'isActive') && $actor->isActive() !== true) {
            throw $this->denied('execution_actor_inactive');
        }

        if (! $actor instanceof Model) {
            return $actor;
        }

        $attributes = $actor->getAttributes();

        foreach (['is_active', 'active'] as $attribute) {
            if (array_key_exists($attribute, $attributes) && $attributes[$attribute] === false) {
                throw $this->denied('execution_actor_inactive');
            }
        }

        if (($attributes['disabled_at'] ?? null) !== null || ($attributes['deactivated_at'] ?? null) !== null) {
            throw $this->denied('execution_actor_inactive');
        }

        $status = $attributes['status'] ?? null;
        if (is_string($status) && in_array(mb_strtolower($status), ['disabled', 'inactive', 'suspended', 'blocked'], true)) {
            throw $this->denied('execution_actor_inactive');
        }

        return $actor;
    }

    private function denied(string $key): ImportExecutionAuthorizationException
    {
        return new ImportExecutionAuthorizationException((string) __('migration-assistant::imports.' . $key));
    }
}
