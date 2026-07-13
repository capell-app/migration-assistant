<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * @method static ExternalPageImportTargetData run(ExternalPageImportTargetData $requestedTarget, ?Authenticatable $actor = null)
 */
final class AuthorizeExternalPageImportTargetAction
{
    use AsAction;

    public function handle(
        ExternalPageImportTargetData $requestedTarget,
        ?Authenticatable $actor = null,
    ): ExternalPageImportTargetData {
        $actor ??= auth()->user();

        if (! $actor instanceof Authenticatable) {
            throw new AuthorizationException((string) __('migration-assistant::imports.external_target_actor_required'));
        }

        $site = Site::query()->find($requestedTarget->siteId);
        if (! $site instanceof Site || ! SiteScope::actorCanUseSite($actor, $site)) {
            throw new AuthorizationException((string) __('migration-assistant::imports.external_target_site_not_authorized'));
        }

        $layout = Layout::query()->find($requestedTarget->layoutId);
        if (! $layout instanceof Layout || ($layout->site_id !== null && $layout->site_id !== $this->integerKey($site))) {
            throw new RuntimeException((string) __('migration-assistant::imports.external_target_layout_invalid'));
        }

        $blueprint = Blueprint::query()->find($requestedTarget->blueprintId);
        if (! $blueprint instanceof Blueprint || $blueprint->getRawOriginal('type') !== BlueprintSubjectEnum::Page->value) {
            throw new RuntimeException((string) __('migration-assistant::imports.external_target_blueprint_invalid'));
        }

        if (! $this->siteSupportsLanguage($site, $requestedTarget->languageId)) {
            throw new RuntimeException((string) __('migration-assistant::imports.external_target_language_invalid'));
        }

        return new ExternalPageImportTargetData(
            siteId: $this->integerKey($site),
            layoutId: $this->integerKey($layout),
            blueprintId: $this->integerKey($blueprint),
            languageId: $requestedTarget->languageId,
        );
    }

    private function integerKey(Model $model): int
    {
        $key = $model->getKey();

        if (! is_int($key)) {
            throw new RuntimeException('Expected an integer model key.');
        }

        return $key;
    }

    private function siteSupportsLanguage(Site $site, int $languageId): bool
    {
        if ((int) $site->language_id === $languageId) {
            return true;
        }

        return $site->languages()->whereKey($languageId)->exists();
    }
}
