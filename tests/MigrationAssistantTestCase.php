<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Core\Facades\CapellCore;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Capell\MigrationAssistant\Providers\MigrationAssistantServiceProvider;
use Capell\Tests\AbstractTestCase;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Livewire\LivewireServiceProvider;
use Override;

abstract class MigrationAssistantTestCase extends AbstractTestCase
{
    use CreatesAdminUser;

    protected function getPackageServiceName(): string
    {
        return 'capell-migration-assistant';
    }

    protected function fakeMigrationAssistantLocalStorage(): void
    {
        $testToken = getenv('TEST_TOKEN') ?: 'sequential';
        $storageToken = substr(hash('sha256', $testToken . '|' . getmypid()), 0, 16);

        ParallelTesting::resolveTokenUsing(static fn (): string => $storageToken);

        Storage::fake('local');
    }

    /** @return array<int, class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            LivewireServiceProvider::class,
            AdminServiceProvider::class,
            MigrationAssistantServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        CapellCore::registerPackage(
            AdminServiceProvider::$packageName,
            path: InstalledVersions::getInstallPath('capell-app/admin'),
        );
        CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName);

        CapellCore::registerPackage(
            MigrationAssistantServiceProvider::$packageName,
            path: realpath(__DIR__ . '/../') ?: null,
        );
        CapellCore::forcePackageInstalled(MigrationAssistantServiceProvider::$packageName);

        CapellAdmin::contributeToAdminSurface(
            AdminSurfaceContributionData::resource(ImportSessionResource::class, group: 'ImportSession'),
        );
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(ImportPagesPage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(ImportSitesPage::class));
    }
}
