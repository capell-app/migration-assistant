<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Enums;

enum ImportSessionKind: string
{
    case PageImport = 'page-import';
    case SiteImport = 'site-import';
}
