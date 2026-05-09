<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Enums;

enum MigrationAssistantPermission: string
{
    case PageExport = 'page.export';
    case SiteExport = 'site.export';
    case PageImport = 'page.import';
    case SiteImport = 'site.import';
    case PageImportUpdateSharedRelations = 'page.import.update-shared-relations';
    case PageImportPublishLive = 'page.import.publish-live';
    case ImportSessionView = 'import-session.view';
    case ImportSessionCancel = 'import-session.cancel';
    case ImportSessionRetry = 'import-session.retry';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
