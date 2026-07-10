<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAdminResource;
use Capell\Core\Contracts\Extensions\RegistersExtensionPermission;
use Capell\MigrationAssistant\Console\Commands\ExecuteMigrationAssistantRollbackCommand;
use Capell\MigrationAssistant\Console\Commands\ExportMigrationAssistantPackageCommand;
use Capell\MigrationAssistant\Console\Commands\ImportMigrationAssistantPackageCommand;
use Capell\MigrationAssistant\Console\Commands\ReclaimStaleImportSessionsCommand;
use Capell\MigrationAssistant\Console\Commands\ShowMigrationAssistantRollbackReportCommand;
use Capell\MigrationAssistant\Console\Commands\ShowMigrationAssistantStatusCommand;
use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Enums\PackageType;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck;
use Capell\MigrationAssistant\Manifest\ImportPagesPageContribution;
use Capell\MigrationAssistant\Manifest\ImportSessionResourceContribution;
use Capell\MigrationAssistant\Manifest\ImportSitesPageContribution;
use Capell\MigrationAssistant\Manifest\MigrationAssistantConsoleCommandsContribution;
use Capell\MigrationAssistant\Manifest\MigrationAssistantHealthContribution;
use Capell\MigrationAssistant\Manifest\MigrationAssistantModelsContribution;
use Capell\MigrationAssistant\Manifest\MigrationAssistantPermissionsContribution;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Carbon\CarbonImmutable;

it('serialises into a stable manifest array', function (): void {
    $manifest = new PackageManifest(
        packageType: PackageType::PageExport,
        capellVersion: '12.0.0',
        exportedAt: CarbonImmutable::parse('2026-04-17T00:00:00Z'),
        sourceEnvironment: 'testing',
        sourceLiveVersionId: 42,
        pageCount: 2,
        siteCount: 0,
        relationCounts: ['layouts' => 1],
        note: 'release candidate',
        sourceWorkspaceId: 3,
    );

    $array = $manifest->withChecksums(['payload' => 'sha256-abc'])->toArray();

    expect($array)
        ->toHaveKey('schema_version', PackageManifest::SCHEMA_VERSION)
        ->toHaveKey('package_type', 'page-export')
        ->toHaveKey('source_workspace_id', 3)
        ->toHaveKey('source_live_version_id', 42)
        ->toHaveKey('page_count', 2)
        ->toHaveKey('relation_counts', ['layouts' => 1])
        ->toHaveKey('note', 'release candidate')
        ->toHaveKey('checksums', ['payload' => 'sha256-abc']);
});

it('declares all committed marketplace screenshots', function (): void {
    $packagePath = dirname(__DIR__, 3);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $marketplaceScreenshots = data_get($manifest, 'marketplace.screenshots', []);

    throw_unless(is_array($marketplaceScreenshots), RuntimeException::class, 'Migration Assistant marketplace screenshots must be an array.');

    $declaredPaths = array_map(
        static function (mixed $screenshot): string {
            throw_unless(is_array($screenshot), RuntimeException::class, 'Migration Assistant marketplace screenshot entries must be arrays.');

            $path = $screenshot['path'] ?? null;
            $alt = $screenshot['alt'] ?? null;
            $caption = $screenshot['caption'] ?? null;

            throw_unless(is_string($path), RuntimeException::class, 'Migration Assistant marketplace screenshot paths must be strings.');
            throw_unless(is_string($alt), RuntimeException::class, 'Migration Assistant marketplace screenshot alt text must be strings.');
            throw_unless(is_string($caption), RuntimeException::class, 'Migration Assistant marketplace screenshot captions must be strings.');

            return $path;
        },
        $marketplaceScreenshots,
    );

    $requiredPaths = [
        'docs/assets/marketplace/extension-card.jpg',
    ];

    expect($declaredPaths)->toContain(...$requiredPaths);

    foreach ($declaredPaths as $declaredPath) {
        expect(file_exists($packagePath . '/' . $declaredPath))->toBeTrue();
    }
});

it('declares the measured admin wizard query budget', function (): void {
    $packagePath = dirname(__DIR__, 3);
    $manifest = capell_json_file_array($packagePath . '/capell.json');

    expect(data_get($manifest, 'performance.adminQueryBudget'))->toBe(120);
});

it('declares migration assistant install surfaces and contribution traceability', function (): void {
    $packagePath = dirname(__DIR__, 3);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $permissions = [
        'page.export',
        'page.import',
        'page.import.update-shared-relations',
        'page.import.publish-live',
        'import-session.view',
        'import-session.cancel',
        'import-session.retry',
    ];

    expect($manifest)
        ->toHaveKey('name', 'capell-app/migration-assistant')
        ->and(data_get($manifest, 'database.requiredTables', []))->toBe([
            'import_sessions',
            'import_rollback_reports',
            'import_rollback_audits',
        ])
        ->and($manifest['permissions'] ?? [])->toBe($permissions)
        ->and(data_get($manifest, 'security.adminSurface.permissions', []))->toBe($permissions)
        ->and(data_get($manifest, 'contributionTraceability.deferredContributions'))->toBe([]);

    expect($manifest['contributes'] ?? [])->toContain(
        [
            'type' => 'admin-page',
            'class' => ImportPagesPageContribution::class,
            'pageClass' => ImportPagesPage::class,
            'labelKey' => 'capell-admin::exchanger.import_pages',
        ],
        [
            'type' => 'admin-page',
            'class' => ImportSitesPageContribution::class,
            'pageClass' => ImportSitesPage::class,
            'labelKey' => 'capell-admin::exchanger.import_sites',
        ],
        [
            'type' => 'admin-resource',
            'class' => ImportSessionResourceContribution::class,
            'resourceClass' => ImportSessionResource::class,
        ],
        [
            'type' => 'model',
            'class' => MigrationAssistantModelsContribution::class,
            'modelClasses' => [
                ImportSession::class,
                ImportRollbackReport::class,
                ImportRollbackAudit::class,
            ],
        ],
        [
            'type' => 'configurator',
            'class' => MigrationAssistantConsoleCommandsContribution::class,
            'commands' => [
                'migration-assistant:export',
                'migration-assistant:import',
                'migration-assistant:status',
                'migration-assistant:reclaim-stale',
                'migration-assistant:rollback-report',
                'migration-assistant:rollback-execute',
            ],
            'commandClasses' => [
                ExportMigrationAssistantPackageCommand::class,
                ImportMigrationAssistantPackageCommand::class,
                ShowMigrationAssistantStatusCommand::class,
                ReclaimStaleImportSessionsCommand::class,
                ShowMigrationAssistantRollbackReportCommand::class,
                ExecuteMigrationAssistantRollbackCommand::class,
            ],
        ],
        [
            'type' => 'permission',
            'class' => MigrationAssistantPermissionsContribution::class,
            'permissions' => $permissions,
        ],
        [
            'type' => 'health-check',
            'class' => MigrationAssistantHealthContribution::class,
            'checkClass' => MigrationAssistantHealthCheck::class,
        ],
    );
});

it('keeps migration assistant manifest contribution classes on core extension contracts', function (): void {
    expect(class_implements(ImportPagesPageContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(ImportSitesPageContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(ImportSessionResourceContribution::class))->toContain(RegistersExtensionAdminResource::class)
        ->and(class_implements(MigrationAssistantModelsContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(MigrationAssistantConsoleCommandsContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(MigrationAssistantPermissionsContribution::class))->toContain(RegistersExtensionPermission::class)
        ->and(class_implements(MigrationAssistantHealthContribution::class))->toContain(ChecksExtensionHealth::class)
        ->and(MigrationAssistantHealthContribution::compatibleCapellApiVersion())->toBe('^4.0');
});
