<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Permission\Models\Permission;

/**
 * Idempotently install the migration-assistant permission matrix described in
 * §6.9 of the recovery-center plan. Running it repeatedly is safe -
 * existing rows are located via `firstOrCreate`.
 *
 * The permission names are the stable public contract; roles are left
 * to the application seeder so each install can map them onto its own
 * tiering.
 */
class InstallMigrationAssistantPermissionsAction
{
    use AsAction;

    public const string PERMISSION_PAGE_EXPORT = 'page.export';

    public const string PERMISSION_SITE_EXPORT = 'site.export';

    public const string PERMISSION_PAGE_IMPORT = 'page.import';

    public const string PERMISSION_SITE_IMPORT = 'site.import';

    public const string PERMISSION_PAGE_IMPORT_UPDATE_SHARED = 'page.import.update-shared-relations';

    public const string PERMISSION_PAGE_IMPORT_PUBLISH_LIVE = 'page.import.publish-live';

    public const string PERMISSION_IMPORT_SESSION_VIEW = 'import-session.view';

    public const string PERMISSION_IMPORT_SESSION_CANCEL = 'import-session.cancel';

    public const string PERMISSION_IMPORT_SESSION_RETRY = 'import-session.retry';

    /**
     * @return list<string>
     */
    public static function permissionNames(): array
    {
        return MigrationAssistantPermission::names();
    }

    public function handle(string $guardName = 'web'): void
    {
        foreach (MigrationAssistantPermission::cases() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => $guardName,
            ]);
        }
    }
}
