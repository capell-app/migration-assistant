<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Enums;

use Filament\Support\Contracts\HasLabel;

enum PackageType: string implements HasLabel
{
    case PageExport = 'page-export';
    case SiteExport = 'site-export';

    public function getLabel(): string
    {
        return __('capell-migration-assistant::imports.enums.package_type.' . $this->value);
    }
}
