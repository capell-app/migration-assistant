<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Manifest;

use Capell\Core\Contracts\Extensions\DeclaresPermissions;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionPermission;
use Capell\Core\Data\Permissions\PermissionData;
use Capell\Core\Data\Permissions\PermissionSetData;

final class MigrationAssistantPermissionsContribution implements DeclaresPermissions, ExtensionContribution, RegistersExtensionPermission
{
    /** The keys capell.json `permissions` lists, which its checks name literally. */
    public const array KEYS = [
        'page.export',
        'page.import',
        'page.import.update-shared-relations',
        'page.import.publish-live',
        'import-session.view',
        'import-session.cancel',
        'import-session.retry',
        'import-session.rollback',
        'import-session.legal-hold',
    ];

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }

    public function permissions(): PermissionSetData
    {
        return new PermissionSetData(array_map(
            static fn (string $key): PermissionData => new PermissionData(
                key: $key,
                label: (string) __('capell-migration-assistant::permissions.labels.' . self::translationKey($key)),
                group: (string) __('capell-migration-assistant::permissions.groups.' . self::translationKey($key)),
            ),
            self::KEYS,
        ));
    }

    /** Translation keys cannot hold dots, which Laravel reads as nesting. */
    private static function translationKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
