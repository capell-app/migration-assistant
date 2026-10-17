<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\ReauthorizeImportSessionExecutionAction;
use Capell\MigrationAssistant\Data\DependencyGraph;
use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Enums\PackageType;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Export\PackageWriter;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('round-trips reason and alternatives through ResolutionMap::toArray and hydration', function (): void {
    $alternative = new MatchResolution(
        localId: 2,
        strategy: 'fingerprint',
        confidence: 0.7,
        reason: 'schema fingerprint match',
    );

    $primary = new MatchResolution(
        localId: 1,
        strategy: 'key',
        confidence: 1.0,
        reason: 'exact key match on "home"',
        alternatives: [$alternative],
    );

    $map = new ResolutionMap(
        resolved: ['layout:99' => $primary],
        unresolved: ['type:50'],
    );

    $encoded = $map->toArray();

    expect($encoded['resolved']['layout:99']['reason'])->toBe('exact key match on "home"')
        ->and($encoded['resolved']['layout:99']['alternatives'])->toHaveCount(1)
        ->and($encoded['resolved']['layout:99']['alternatives'][0]['strategy'])->toBe('fingerprint');

    Storage::fake('local');
    $path = 'migration-assistant/imports/uploads/' . Str::uuid() . '.zip';
    new PackageWriter()->write(Storage::disk('local')->path($path), new PackageManifest(
        packageType: PackageType::PageExport,
        capellVersion: '4.0.0',
        exportedAt: CarbonImmutable::now(),
        sourceEnvironment: 'round-trip',
        sourceLiveVersionId: null,
        pageCount: 0,
        siteCount: 0,
        relationCounts: [],
    ), new DependencyGraph([], [], [], []), [], []);
    $actor = User::factory()->create();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued, 'source_package_path' => $path,
        'resolution_map' => $encoded, 'source_environment' => 'round-trip',
    ]);
    $hydrated = null;
    $reauthorize = Mockery::mock(new ReauthorizeImportSessionExecutionAction);
    app()->instance('LaravelActions:AsFake:' . ReauthorizeImportSessionExecutionAction::class, $reauthorize);
    $reauthorize->shouldReceive('handle')->once()->andReturnUsing(function (ImportSession $executing, PackageReadResult $package, ResolutionMap $map) use ($actor, $session, &$hydrated): User {
        expect($executing->is($session))->toBeTrue();
        $hydrated = $map;

        return $actor;
    });
    $id = $session->getKey();
    throw_unless(is_int($id), RuntimeException::class);
    expect(function () use ($id): void {
        new ExecuteImportPlanJob($id)->handle(resolve(PackageReader::class), resolve(PageImportService::class), resolve(MediaIngestService::class));
    })->toThrow(RuntimeException::class, 'unresolved references');
    throw_unless($hydrated instanceof ResolutionMap, RuntimeException::class, (string) $session->refresh()->failure_reason);

    expect($hydrated->resolved['layout:99']->reason)->toBe('exact key match on "home"')
        ->and($hydrated->resolved['layout:99']->alternatives)->toHaveCount(1)
        ->and($hydrated->resolved['layout:99']->alternatives[0]->localId)->toBe(2)
        ->and($hydrated->resolved['layout:99']->alternatives[0]->strategy)->toBe('fingerprint')
        ->and($hydrated->unresolved)->toBe(['type:50'])
        ->and($session->refresh()->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toContain('unresolved references');
});
