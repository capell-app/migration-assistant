<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Manifest;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class MigrationAssistantHealthContribution implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
