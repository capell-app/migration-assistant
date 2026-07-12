<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

it('reports a compatible capell api version', function (): void {
    expect(MigrationAssistantHealthCheck::compatibleCapellApiVersion())->toBe('^0.0');
});

it('runs real diagnostics returning check results', function (): void {
    $results = MigrationAssistantHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(3)
        ->and($results->every(static fn (mixed $result): bool => $result instanceof DoctorCheckResultData))->toBeTrue();
});

it('runs a single diagnostic matching a manifest health key', function (): void {
    $results = MigrationAssistantHealthCheck::runDiagnostics('migration-assistant.package-reader');

    expect($results)->toHaveCount(1)
        ->and($results->first())->toBeInstanceOf(DoctorCheckResultData::class)
        ->and($results->first()?->label)->toBe(__('migration-assistant::imports.health.package_reader.label'))
        ->and(MigrationAssistantHealthCheck::runDiagnostics('migration-assistant.unknown'))->toBeEmpty();
});

it('passes when tables, morph aliases, readers, and media limit are present', function (): void {
    $results = MigrationAssistantHealthCheck::runDiagnostics();

    expect(MigrationAssistantHealthCheck::passed())->toBeTrue()
        ->and($results->every(static fn (DoctorCheckResultData $result): bool => $result->passed))->toBeTrue();
});

it('fails the storage table check when an import table is missing', function (): void {
    $tableName = (new ImportSession)->getTable();

    Schema::drop($tableName);

    $check = new MigrationAssistantHealthCheck;

    expect($check->missingTables())->toContain($tableName)
        ->and($check->storageTablesCheck()->passed)->toBeFalse()
        ->and(MigrationAssistantHealthCheck::passed())->toBeFalse();
});

it('confirms the import models are registered in the morph map', function (): void {
    $check = new MigrationAssistantHealthCheck;

    expect($check->unregisteredMorphAliases())->toBe([])
        ->and($check->modelMorphAliasCheck()->passed)->toBeTrue();
});

it('confirms csv and xml source readers are resolvable', function (): void {
    $check = new MigrationAssistantHealthCheck;

    expect($check->unsupportedSourceExtensions())->toBe([])
        ->and($check->importSourceReadersCheck()->passed)->toBeTrue()
        ->and($check->packageReaderCheck()->passed)->toBeTrue();
});

it('fails the package reader check when a package size limit is not positive', function (): void {
    Config::set('migration-assistant.limits.max_package_uncompressed_bytes', 0);

    $check = new MigrationAssistantHealthCheck;

    expect($check->invalidPositivePackageLimitKeys())->toContain('migration-assistant.limits.max_package_uncompressed_bytes')
        ->and($check->packageReaderCheck()->passed)->toBeFalse()
        ->and(MigrationAssistantHealthCheck::passed())->toBeFalse();
});

it('fails the media ingest limit check when no positive limit is configured', function (): void {
    Config::set('migration-assistant.limits.max_media_bytes', 0);

    $check = new MigrationAssistantHealthCheck;

    expect($check->configuredMaximumMediaBytes())->toBe(0)
        ->and($check->mediaIngestLimitCheck()->passed)->toBeFalse()
        ->and(MigrationAssistantHealthCheck::passed())->toBeFalse();
});
