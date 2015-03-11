<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Policies;

use Capell\Admin\Support\SiteScope;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

class ImportSessionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->isGlobalAdmin($user)
            && $this->canViewImportSessions($user);
    }

    public function view(Authenticatable $user, ImportSession $importSession): bool
    {
        return $this->isGlobalAdmin($user)
            && $this->canViewImportSessions($user);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, ImportSession $importSession): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, ImportSession $importSession): bool
    {
        return false;
    }

    private function isGlobalAdmin(Authenticatable $user): bool
    {
        if ($this->hasDeclaredPublicMethod($user, 'isGlobalAdmin')) {
            try {
                if ($user->isGlobalAdmin() === true) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        if ($this->hasDeclaredPublicMethod($user, 'hasRole')) {
            try {
                return SiteScope::isGlobalActor($user);
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private function canViewImportSessions(Authenticatable $user): bool
    {
        if (! $this->hasDeclaredPublicMethod($user, 'checkPermissionTo')) {
            return false;
        }

        try {
            return $user->checkPermissionTo(MigrationAssistantPermission::ImportSessionView->value) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasDeclaredPublicMethod(Authenticatable $user, string $method): bool
    {
        return in_array($method, get_class_methods($user), true);
    }
}
