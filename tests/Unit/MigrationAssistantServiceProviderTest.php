<?php

declare(strict_types=1);

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
it('does not require the permissions table during install-provider boot', function (): void {
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
