<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\HtmlCache\Actions\MarkCachedUrlsForSiteStaleAction;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Marks every cached URL of the sites a rollback touched stale, once the rollback has committed. Without the HTML
 * cache package there is nothing to invalidate, and a failed invalidation never fails the rollback.
 *
 * @method static int run(list<int> $siteIds)
 */
final class InvalidateRollbackCachesAction
{
    use AsFake;
    use AsObject;

    /** @param list<int> $siteIds */
    public function handle(array $siteIds): int
    {
        if ($siteIds === [] || ! CapellCore::isPackageEnabled('capell-app/html-cache')) {
            return 0;
        }

        if (! CapellCore::isPackageAvailable('capell-app/html-cache')) {
            return 0;
        }

        if (! Schema::hasTable('cached_model_urls')) {
            return 0;
        }
        $marked = 0;
        foreach ($siteIds as $siteId) {
            try {
                $marked += MarkCachedUrlsForSiteStaleAction::run($siteId, 'import_rollback');
            } catch (Throwable $exception) {
                logger()->warning('Import rollback could not mark cached URLs stale.', ['site_id' => $siteId, 'exception' => $exception::class]);
            }
        }

        return $marked;
    }
}
