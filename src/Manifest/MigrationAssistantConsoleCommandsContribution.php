<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class MigrationAssistantConsoleCommandsContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
