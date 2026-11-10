<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;

/** Reads the existing global role/permission assignments through the restricted reader only. */
final class AuthorizeInPlaceRollbackActorAction
{
    public function handle(string $reader, Model $actor): bool
    {
        $tables = config('permission.table_names');
        if (! is_array($tables) || ! is_scalar($actor->getKey())) {
            return false;
        }

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            if (! is_string($tables[$table] ?? null)) {
                return false;
            }
        }

        $db = DB::connection($reader);
        $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
        $permissionKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
        $modelKey = config('permission.column_names.model_morph_key') ?: 'model_id';
        $teamKey = config('permission.column_names.team_foreign_key') ?: 'team_id';
        if (! is_string($roleKey) || ! is_string($permissionKey) || ! is_string($modelKey) || ! is_string($teamKey)) {
            return false;
        }

        $guard = Guard::getDefaultName($actor);
        $roles = $db->table($tables['model_has_roles'])->where('model_type', $actor->getMorphClass())->where($modelKey, $actor->getKey());
        if (config('permission.teams')) {
            $roles->whereNull($teamKey);
        }

        $roleIds = $roles->pluck($roleKey)->all();
        $superAdmin = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $globalRole = $db->table($tables['roles'])->whereIn('id', $roleIds)->where('name', $superAdmin)->where('guard_name', $guard);
        if (config('permission.teams')) {
            $globalRole->whereNull($teamKey);
        }

        if (! $globalRole->exists()) {
            return false;
        }

        $permissionId = $db->table($tables['permissions'])->where('name', MigrationAssistantPermission::ImportSessionRollback->value)->where('guard_name', $guard)->value('id');
        if ($permissionId === null) {
            return false;
        }

        $direct = $db->table($tables['model_has_permissions'])->where('model_type', $actor->getMorphClass())->where($modelKey, $actor->getKey())->where($permissionKey, $permissionId);
        if (config('permission.teams')) {
            $direct->whereNull($teamKey);
        }

        return $direct->exists() || $db->table($tables['role_has_permissions'])->whereIn($roleKey, $roleIds)->where($permissionKey, $permissionId)->exists();
    }
}
