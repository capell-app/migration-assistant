<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Enums;

use Filament\Support\Contracts\HasLabel;

enum ImportSessionKind: string implements HasLabel
{
    case PageImport = 'page-import';
    case SiteImport = 'site-import';

    public function getLabel(): string
    {
        return __('capell-migration-assistant::imports.enums.import_session_kind.' . $this->value);
    }
}
