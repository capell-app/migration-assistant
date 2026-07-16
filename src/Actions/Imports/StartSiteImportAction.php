<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static PageImportWizardStateData run(array<string, mixed> $state)
 */
final class StartSiteImportAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(array $state): PageImportWizardStateData
    {
        return StartPageImportAction::run($state, ImportSessionKind::SiteImport);
    }
}
