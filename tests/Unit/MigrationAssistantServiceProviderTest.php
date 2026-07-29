<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\InstallMigrationAssistantPermissionsAction;
use Capell\MigrationAssistant\Contracts\MigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\MigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\PageCollisionDetector;
use Capell\MigrationAssistant\Services\Import\CsvReader;
use Capell\MigrationAssistant\Services\Import\PageUrlCollisionDetector;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Capell\MigrationAssistant\Services\Import\XmlReader;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;

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

it('does not install permissions during application boot', function (): void {
    $provider = file_get_contents(dirname(__DIR__, 2) . '/src/Providers/MigrationAssistantServiceProvider.php');
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/capell.json'), true);
    $providers = is_array($manifest) ? $manifest['providers'] ?? null : null;

    if (! is_array($providers)) {
        throw new RuntimeException('Expected package manifest providers to be an array.');
    }

    $installProviders = $providers['install'] ?? [];

    if (! is_array($installProviders)) {
        throw new RuntimeException('Expected package install providers to be an array.');
    }

    expect($provider)->not->toBeFalse()
        ->and($provider)->not->toContain('InstallMigrationAssistantPermissionsAction::run()')
        ->and($installProviders)->toBe([
            'Capell\\MigrationAssistant\\Providers\\MigrationAssistantInstallServiceProvider',
        ]);
});

it('does not require the permissions table during install-provider boot', function (): void {
    InstallMigrationAssistantPermissionsAction::run();

    expect(true)->toBeTrue();
});
