<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Bridges\HtmlCache;

use Capell\Core\Facades\CapellCore;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForSiteStaleAction;

/** Marks HTML Cache entries stale; each method reports 0 marked when HTML Cache is not available. */
final class HtmlCacheStaleBridge
{
    public static function markAll(string $reason): int
    {
        if (! CapellCore::isPackageAvailable('capell-app/html-cache')) {
            return 0;
        }

        return MarkAllCachedUrlsStaleAction::run($reason);
    }

    public static function markSite(int $siteId, string $reason): int
    {
        if (! CapellCore::isPackageAvailable('capell-app/html-cache')) {
            return 0;
        }

        return MarkCachedUrlsForSiteStaleAction::run($siteId, $reason);
    }
}
