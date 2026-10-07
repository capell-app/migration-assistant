<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Providers;

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\MigrationAssistant\Console\Commands\ExecuteMigrationAssistantRollbackCommand;
use Capell\MigrationAssistant\Console\Commands\ExportMigrationAssistantPackageCommand;
use Capell\MigrationAssistant\Console\Commands\ImportMigrationAssistantPackageCommand;
use Capell\MigrationAssistant\Console\Commands\ReclaimStaleImportSessionsCommand;
use Capell\MigrationAssistant\Console\Commands\ShowMigrationAssistantRollbackReportCommand;
use Capell\MigrationAssistant\Console\Commands\ShowMigrationAssistantStatusCommand;
use Capell\MigrationAssistant\Contracts\MigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\MigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\NullPageImportTargetResolver;
use Capell\MigrationAssistant\Contracts\PageCollisionDetector;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Events\ImportCompleted;
use Capell\MigrationAssistant\Events\ImportFailed;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Capell\MigrationAssistant\Listeners\SendImportSessionNotifications;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Policies\ImportSessionPolicy;
use Capell\MigrationAssistant\Services\Import\CsvReader;
use Capell\MigrationAssistant\Services\Import\PageUrlCollisionDetector;
use Capell\MigrationAssistant\Services\Import\Resolvers\FingerprintMatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\KeyedMatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\MediaMatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Capell\MigrationAssistant\Services\Import\XmlReader;
use Capell\MigrationAssistant\Support\AdminPageExporter;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\MigrationAssistant\Support\ImportTargetRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Override;
use Spatie\LaravelPackageTools\Package;

final class MigrationAssistantServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'migration-assistant';

    public static string $packageName = 'capell-app/migration-assistant';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('migration-assistant')
            ->hasTranslations()
            ->hasViews()
            ->hasCommands([
                ExecuteMigrationAssistantRollbackCommand::class,
                ExportMigrationAssistantPackageCommand::class,
                ImportMigrationAssistantPackageCommand::class,
                ReclaimStaleImportSessionsCommand::class,
                ShowMigrationAssistantRollbackReportCommand::class,
                ShowMigrationAssistantStatusCommand::class,
            ])
            ->hasMigrations([
                '2026_05_10_190859_01_create_import_sessions_table',
                '2026_05_10_190859_02_create_import_rollback_reports_table',
                '2026_06_04_000001_rename_import_rollback_reports_table',
                '2026_07_10_000002_encrypt_import_diagnostics',
                '2026_07_10_120000_harden_import_rollback_provenance',
            ]);
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->registerInstalledPackage();
    }

    private function registerInstalledPackage(): void
    {
        $this->registerAdminPanelExtensions();

        $this->surface()->models([
            ImportRollbackReport::class,
            ImportRollbackAudit::class,
            ImportSession::class,
        ]);

        $this->app->singletonIf(MigrationAssistantContextResolver::class, NullMigrationAssistantContextResolver::class);
        $this->app->singletonIf(MigrationAssistantRowContributor::class, NullMigrationAssistantRowContributor::class);
        $this->app->singletonIf(PageImportTargetResolver::class, NullPageImportTargetResolver::class);
        $this->app->singletonIf(PageCollisionDetector::class, PageUrlCollisionDetector::class);

        $this->app->singleton(ImportTargetRegistry::class);
        $this->app->singleton(ImportSessionExecutorRegistry::class);
        $this->app->singleton(ImportSourceRegistry::class, static function (): ImportSourceRegistry {
            $registry = new ImportSourceRegistry;
            $registry->register(new CsvReader);
            $registry->register(new XmlReader);

            return $registry;
        });

        $this->app->singleton(
            RelationMatchResolverRegistry::class,
            static function (): RelationMatchResolverRegistry {
                $registry = new RelationMatchResolverRegistry;
                $registry->register('layouts', new KeyedMatchResolver(Layout::class, scopeToSite: true));
                $registry->register('layouts', new FingerprintMatchResolver(Layout::class, scopeToSite: true));
                $registry->register('blueprints', new KeyedMatchResolver(Blueprint::class));
                $registry->register('blueprints', new FingerprintMatchResolver(Blueprint::class));
                $registry->register('sites', new KeyedMatchResolver(Site::class, keyColumn: 'slug'));
                $registry->register('media', new MediaMatchResolver);

                return $registry;
            },
        );

        if (interface_exists(PageExporter::class)) {
            $this->app->singleton(PageExporter::class, AdminPageExporter::class);
        }

        if (class_exists(SendImportSessionNotifications::class)) {
            Event::listen(ImportCompleted::class, [SendImportSessionNotifications::class, 'handleCompleted']);
            Event::listen(ImportFailed::class, [SendImportSessionNotifications::class, 'handleFailed']);
        }

        if (class_exists(ImportSessionPolicy::class)) {
            Gate::policy(ImportSession::class, ImportSessionPolicy::class);
        }

    }

    private function registerAdminPanelExtensions(): void
    {
        if (! class_exists(CapellAdminManager::class) || ! class_exists(ImportSessionResource::class)) {
            return;
        }

        $registerImportSessionResource = static function (CapellAdminManager $capellAdminManager): void {
            $capellAdminManager->contributeToAdminSurface(
                AdminSurfaceContributionData::resource(ImportSessionResource::class, group: 'ImportSession'),
            );
            $capellAdminManager->contributeToAdminSurface(AdminSurfaceContributionData::page(ImportPagesPage::class));
            $capellAdminManager->contributeToAdminSurface(AdminSurfaceContributionData::page(ImportSitesPage::class));
        };

        if ($this->app->bound(CapellAdminManager::class)) {
            $registerImportSessionResource($this->app->make(CapellAdminManager::class));

            return;
        }

        $this->app->afterResolving(CapellAdminManager::class, $registerImportSessionResource);
    }
}
