<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Providers;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Illuminate\Support\ServiceProvider;
use Override;

final class AdminServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime(MigrationAssistantServiceProvider::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(ImportSessionResource::class, group: 'ImportSession'));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(ImportPagesPage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(ImportSitesPage::class));
    }
}
