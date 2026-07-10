<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use BackedEnum;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Exceptions\ImportExecutionAuthorizationException;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Revalidates the queued import's original actor against live account,
 * permission, target and site-scope state immediately before import writes.
 *
 * @method static Authenticatable run(ImportSession $session, PackageReadResult $package, ResolutionMap $resolutionMap)
 */
final class ReauthorizeImportSessionExecutionAction
{
    use AsAction;

    public function handle(
        ImportSession $session,
        PackageReadResult $package,
        ResolutionMap $resolutionMap,
    ): Authenticatable {
        $actor = $this->freshActor($session);

        $this->assertActorIsActive($actor);
        $this->assertTargetHasNotDrifted($session);
        $this->assertActorCanImport($actor, $session, $package, $resolutionMap);

        return $actor;
    }

    private function freshActor(ImportSession $session): Authenticatable
    {
        if ($session->user_id === null) {
            throw $this->denied('execution_actor_missing');
        }

        $actor = Auth::guard()->getProvider()->retrieveById($session->user_id);

        if (! $actor instanceof Authenticatable) {
            throw $this->denied('execution_actor_missing');
        }

        return $actor;
    }

    private function assertActorIsActive(Authenticatable $actor): void
    {
        if (method_exists($actor, 'isActive') && $actor->isActive() !== true) {
            throw $this->denied('execution_actor_inactive');
        }

        if (! $actor instanceof Model) {
            return;
        }

        foreach (['is_active', 'active'] as $attribute) {
            if ($actor->getAttribute($attribute) === false) {
                throw $this->denied('execution_actor_inactive');
            }
        }

        if ($actor->getAttribute('disabled_at') !== null || $actor->getAttribute('deactivated_at') !== null) {
            throw $this->denied('execution_actor_inactive');
        }

        $status = $actor->getAttribute('status');
        $status = $status instanceof BackedEnum ? $status->value : $status;

        if (is_string($status) && in_array(mb_strtolower($status), ['disabled', 'inactive', 'suspended', 'blocked'], true)) {
            throw $this->denied('execution_actor_inactive');
        }
    }

    private function assertTargetHasNotDrifted(ImportSession $session): void
    {
        $target = resolve(PageImportTargetResolver::class)->resolve($session);
        $storedTargetType = $session->target_type;

        if (is_string($storedTargetType) && $storedTargetType !== '' && $target->type !== $storedTargetType) {
            throw $this->denied('execution_target_drifted');
        }

        if ($session->target_id !== null && (string) $target->id !== (string) $session->target_id) {
            throw $this->denied('execution_target_drifted');
        }
    }

    private function assertActorCanImport(
        Authenticatable $actor,
        ImportSession $session,
        PackageReadResult $package,
        ResolutionMap $resolutionMap,
    ): void {
        if ($session->kind === ImportSessionKind::SiteImport) {
            $this->assertGlobalImportPermission($actor);

            return;
        }

        $targetSites = $this->targetSites($package, $resolutionMap);

        if ($targetSites === []) {
            throw $this->denied('execution_target_missing');
        }

        if (SiteScope::isGlobalActor($actor)) {
            $this->assertGlobalImportPermission($actor);

            return;
        }

        foreach ($targetSites as $site) {
            if (! SiteScope::actorCanUseSite($actor, $site) || ! $this->hasImportPermissionForSite($actor, $site)) {
                throw $this->denied('execution_site_access_revoked');
            }
        }
    }

    private function assertGlobalImportPermission(Authenticatable $actor): void
    {
        if (! method_exists($actor, 'can') || $actor->can(MigrationAssistantPermission::PageImport->value) !== true) {
            throw $this->denied('execution_permission_revoked');
        }
    }

    private function hasImportPermissionForSite(Authenticatable $actor, Site $site): bool
    {
        if (! method_exists($actor, 'hasPermissionForSite')) {
            return false;
        }

        return $actor->hasPermissionForSite($site, MigrationAssistantPermission::PageImport->value) === true;
    }

    /**
     * @return list<Site>
     */
    private function targetSites(PackageReadResult $package, ResolutionMap $resolutionMap): array
    {
        $siteIds = [];

        foreach ($package->payload as $entryPath => $contents) {
            if (! str_starts_with($entryPath, 'pages/')) {
                continue;
            }

            try {
                /** @var array<string, mixed> $descriptor */
                $descriptor = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw $this->denied('execution_target_missing');
            }

            $siteId = $this->targetSiteId($descriptor, $resolutionMap);

            if ($siteId === null) {
                throw $this->denied('execution_target_missing');
            }

            $siteIds[] = $siteId;
        }

        $siteIds = array_values(array_unique($siteIds));
        $sites = Site::query()->whereKey($siteIds)->get()->keyBy(static fn (Site $site): int|string => $site->getKey());

        if ($sites->count() !== count($siteIds)) {
            throw $this->denied('execution_target_drifted');
        }

        return array_values($sites->all());
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function targetSiteId(array $descriptor, ResolutionMap $resolutionMap): int|string|null
    {
        $sharedRelations = is_array($descriptor['shared_relations'] ?? null) ? $descriptor['shared_relations'] : [];
        $siteReference = $sharedRelations['site']['ref'] ?? null;

        if (is_string($siteReference)) {
            return $resolutionMap->localIdFor($siteReference);
        }

        $attributes = is_array($descriptor['attributes'] ?? null) ? $descriptor['attributes'] : [];
        $siteId = $attributes['site_id'] ?? null;

        return is_int($siteId) || (is_string($siteId) && is_numeric($siteId)) ? $siteId : null;
    }

    private function denied(string $key): ImportExecutionAuthorizationException
    {
        return new ImportExecutionAuthorizationException((string) __('migration-assistant::imports.' . $key));
    }
}
