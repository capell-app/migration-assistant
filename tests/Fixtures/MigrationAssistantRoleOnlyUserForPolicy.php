<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Fixtures;

use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User;

class MigrationAssistantRoleOnlyUserForPolicy extends User
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /**
     * @param  list<string>|string  $roles
     */
    public function hasRole(array|string $roles, ?string $guard = null): bool
    {
        if (is_array($roles)) {
            return in_array('super_admin', $roles, true);
        }

        return $roles === 'super_admin';
    }

    public function checkPermissionTo(string $permission, ?string $guardName = null): bool
    {
        return $permission === MigrationAssistantPermission::ImportSessionView->value;
    }
}
