<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class ImportPagesPageContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
