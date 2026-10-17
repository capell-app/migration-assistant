<?php

declare(strict_types=1);

use Capell\Admin\Support\CapellAdminManager;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Foundation\Application;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('migration-assistant');
});

it('keeps import admin surfaces out of the public runtime', function (): void {
    InstalledRuntimeState::run(static function (): void {
        $app = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('migration-assistant'), true, static function (Application $app): void {
            $app->instance(RuntimeRoleResolver::class, new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue('public')));
        }, sourceRoot: dirname(__DIR__, 3));
        $owned = [];
        foreach ($app->make(CapellAdminManager::class)->getAdminSurfaceRegistry()->all() as $contributions) {
            foreach ($contributions as $contribution) {
                if (str_starts_with($contribution->class, 'Capell\\MigrationAssistant\\')) {
                    $owned[] = $contribution->class;
                }
            }
        }

        expect($owned)->toBe([]);
    });
});
