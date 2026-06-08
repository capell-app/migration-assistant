<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Filament\Pages;

use BackedEnum;
use Capell\MigrationAssistant\Actions\Imports\StartSiteImportAction;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Override;

class ImportSitesPage extends ImportPagesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::GlobeAlt;

    protected static ?string $slug = 'migration-assistant/recovery-center/import-sites';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::exchanger.import_sites');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::exchanger.import_sites');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[Override]
    protected function startImport(array $data): PageImportWizardStateData
    {
        return StartSiteImportAction::run($data);
    }
}
