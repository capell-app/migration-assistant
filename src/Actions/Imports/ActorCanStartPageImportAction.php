<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Whether an actor may upload and parse a package to start an import. A global actor needs the
 * import permission itself; a site-scoped actor needs it on at least one assigned site, and the
 * execution job re-checks every target site before anything is written.
 *
 * @method static bool run(?Authenticatable $actor)
 */
final class ActorCanStartPageImportAction
{
    use AsFake;
    use AsObject;

    public function handle(?Authenticatable $actor): bool
    {
        if (! $actor instanceof Authenticatable || $actor->getAuthIdentifier() === null) {
            return false;
        }

        try {
            if (SiteAccess::forActor($actor)->isGlobal()) {
                return method_exists($actor, 'can') && $actor->can(MigrationAssistantPermission::PageImport->value) === true;
            }

            if (! method_exists($actor, 'getAssignedSiteIds') || ! method_exists($actor, 'hasPermissionForSite')) {
                return false;
            }

            $siteIds = collect($actor->getAssignedSiteIds())->all();

            foreach (Site::query()->whereKey($siteIds)->get() as $site) {
                if ($actor->hasPermissionForSite($site, MigrationAssistantPermission::PageImport->value) === true) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
