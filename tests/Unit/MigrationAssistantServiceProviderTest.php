<?php

declare(strict_types=1);

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Capell\MigrationAssistant\Actions\InstallMigrationAssistantPackageAction;
use Capell\MigrationAssistant\Actions\InstallMigrationAssistantPermissionsAction;
use Capell\MigrationAssistant\Contracts\MigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\MigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\PageCollisionDetector;
use Capell\MigrationAssistant\Providers\MigrationAssistantServiceProvider;
use Capell\MigrationAssistant\Services\Import\CsvReader;
use Capell\MigrationAssistant\Services\Import\PageUrlCollisionDetector;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Capell\MigrationAssistant\Services\Import\XmlReader;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Capell\MigrationAssistant\Support\Upgrade\InstallMigrationAssistantPermissionsUpgradeStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Permission;

it('registers migration-assistant config and default contracts', function (): void {
    expect(config('migration-assistant.paths.exports'))->toBe('migration-assistant/exports')
        ->and(app()->get(MigrationAssistantContextResolver::class))->toBeInstanceOf(NullMigrationAssistantContextResolver::class)
        ->and(app()->get(MigrationAssistantRowContributor::class))->toBeInstanceOf(NullMigrationAssistantRowContributor::class)
        ->and(app()->get(PageCollisionDetector::class))->toBeInstanceOf(PageUrlCollisionDetector::class);
});

it('registers default external import source readers', function (): void {
    $registry = resolve(ImportSourceRegistry::class);

    expect($registry->readers())
        ->toHaveCount(2)
        ->and($registry->readerFor('people.csv'))->toBeInstanceOf(CsvReader::class)
        ->and($registry->readerFor('content.xml'))->toBeInstanceOf(XmlReader::class);
});

it('registers default relation resolver groups', function (): void {
    $registry = resolve(RelationMatchResolverRegistry::class);

    expect($registry->hasGroup('layouts'))->toBeTrue()
        ->and($registry->hasGroup('blueprints'))->toBeTrue()
        ->and($registry->hasGroup('sites'))->toBeTrue()
        ->and($registry->hasGroup('media'))->toBeTrue();
});

it('boots safely before permission storage exists without writing permissions', function (): void {
    $provider = new MigrationAssistantServiceProvider(app());
    $provider->register();
    $queries = DB::connection()->pretend(fn () => $provider->boot());

    expect(collect($queries)->filter(fn (array $query): bool => preg_match('/\b(?:insert|update|delete)\b/i', $query['query']) === 1)->all())->toBe([]);
});
it('does not require the permissions table when the install action runs', function (): void {
    $permissionTable = config('permission.table_names.permissions');
    config()->set('permission.table_names.permissions', 'missing_permission_storage');
    try {
        expect(Schema::hasTable('missing_permission_storage'))->toBeFalse();
        InstallMigrationAssistantPermissionsAction::run();
        expect(Schema::hasTable('missing_permission_storage'))->toBeFalse();
    } finally {
        config()->set('permission.table_names.permissions', $permissionTable);
    }
});

/**
 * @return array<string, mixed>
 */
function migrationAssistantManifest(): array
{
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);

    return is_array($manifest) ? $manifest : [];
}

it('boots every provider the manifest declares without querying permissions', function (): void {
    $permissionTables = array_values(array_filter([
        config('permission.table_names.permissions'),
        config('permission.table_names.role_has_permissions'),
    ], is_string(...)));
    $declared = data_get(migrationAssistantManifest(), 'providers', []);
    $providers = [];

    foreach (is_array($declared) ? $declared : [] as $bucket) {
        foreach (is_array($bucket) ? $bucket : [] as $providerClass) {
            if (is_string($providerClass) && is_subclass_of($providerClass, ServiceProvider::class)) {
                $providers[] = $providerClass;
            }
        }
    }

    expect($providers)->not->toBeEmpty();

    $queries = DB::connection()->pretend(function () use ($providers): void {
        foreach ($providers as $providerClass) {
            $provider = new $providerClass(app());
            $provider->register();

            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
    });

    $permissionQueries = collect($queries)
        ->filter(static fn (array $query): bool => collect($permissionTables)->contains(static fn (string $table): bool => str_contains($query['query'], $table)))
        ->all();

    expect($permissionQueries)->toBe([]);
});

it('declares permission installation as a package lifecycle action', function (): void {
    $actionClass = data_get(migrationAssistantManifest(), 'actions.install');

    expect($actionClass)->toBe(InstallMigrationAssistantPackageAction::class);
});

it('creates the permission matrix when the package install action runs', function (): void {
    Permission::query()->whereIn('name', InstallMigrationAssistantPermissionsAction::permissionNames())->delete();

    InstallMigrationAssistantPackageAction::run(CapellCore::getPackage('capell-app/migration-assistant'));

    expect(Permission::query()->whereIn('name', InstallMigrationAssistantPermissionsAction::permissionNames())->count())
        ->toBe(count(InstallMigrationAssistantPermissionsAction::permissionNames()));
});

it('creates missing permissions for existing installs through an upgrade step', function (): void {
    Permission::query()->whereIn('name', InstallMigrationAssistantPermissionsAction::permissionNames())->delete();

    $steps = [];

    foreach (app()->tagged('capell.upgrade-steps') as $candidate) {
        if ($candidate instanceof InstallMigrationAssistantPermissionsUpgradeStep) {
            $steps[] = $candidate;
        }
    }

    expect($steps)->toHaveCount(1);

    $step = $steps[0];
    $context = new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []);

    expect($step->package())->toBe('capell-app/migration-assistant')
        ->and($step->run($context))->toBeTrue()
        ->and($step->run($context))->toBeTrue()
        ->and(Permission::query()->whereIn('name', InstallMigrationAssistantPermissionsAction::permissionNames())->count())
        ->toBe(count(InstallMigrationAssistantPermissionsAction::permissionNames()));
});
