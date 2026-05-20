<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Filament\Resources\ImportSessions;

use BackedEnum;
use Capell\Admin\Filament\Concerns\HasConfiguredTable;
use Capell\Admin\Filament\Concerns\HasNavigationBadge;
use Capell\MigrationAssistant\Contracts\ImportSessionSubNavigationExtender;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\Pages\ListImportSessions;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\Pages\ViewImportSession;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\Tables\ImportSessionsTable;
use Capell\MigrationAssistant\Models\ImportSession;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Override;

class ImportSessionResource extends Resource
{
    use HasConfiguredTable;
    use HasNavigationBadge;

    protected static ?string $model = ImportSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowPath;

    protected static ?string $recordTitleAttribute = 'uuid';

    protected static string $tableConfigurator = ImportSessionsTable::class;

    public static function shouldRegisterWithPanel(): bool
    {
        return class_exists(ImportSession::class);
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::exchanger.import_sessions');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return __('capell-admin::navigation.group_system');
    }

    #[Override]
    public static function getPluralModelLabel(): string
    {
        return (string) __('capell-admin::exchanger.import_sessions');
    }

    #[Override]
    public static function getModelLabel(): string
    {
        return (string) __('capell-admin::exchanger.import_session');
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function canAccess(): bool
    {
        return static::shouldRegisterWithPanel() && parent::canAccess();
    }

    #[Override]
    public static function canGloballySearch(): bool
    {
        return SchemaFacade::hasTable('import_sessions') && parent::canGloballySearch();
    }

    public static function getSubNavigation(): array
    {
        $items = [];

        foreach (app()->tagged(ImportSessionSubNavigationExtender::TAG) as $extender) {
            /** @var ImportSessionSubNavigationExtender $extender */
            $items = array_merge($items, $extender->getItems());
        }

        if (Route::has(ImportPagesPage::getRouteName())) {
            $items[] = NavigationItem::make()
                ->label(__('capell-admin::exchanger.import_pages'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(ImportPagesPage::getUrl())
                ->isActiveWhen(fn (): bool => request()->is('*/recovery-center/import-pages*'));
        }

        if (Route::has(ImportSitesPage::getRouteName())) {
            $items[] = NavigationItem::make()
                ->label(__('capell-admin::exchanger.import_sites'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->url(ImportSitesPage::getUrl())
                ->isActiveWhen(fn (): bool => request()->is('*/recovery-center/import-sites*'));
        }

        return $items;
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListImportSessions::route('/'),
            'view' => ViewImportSession::route('/{record}'),
        ];
    }
}
