<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static PageImportWizardStateData run(array<string, mixed> $state)
 */
final class StartSiteImportAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(array $state): PageImportWizardStateData
    {
        return resolve(StartPageImportAction::class)->handle($state, ImportSessionKind::SiteImport);
    }
}
