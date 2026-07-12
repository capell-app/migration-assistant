<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionPermission;

final class MigrationAssistantPermissionsContribution implements ExtensionContribution, RegistersExtensionPermission
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
