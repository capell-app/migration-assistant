<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\MigrationAssistant\Actions\BuildImportRecoveryStatusAction;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Actions\ReclaimStaleImportSessionsAction;
use Capell\MigrationAssistant\Actions\RetryImportSessionAction;
use Capell\MigrationAssistant\Contracts\ImportSessionExecutor;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\DependencyGraph;
use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Data\PageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Enums\PackageType;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Export\PackageWriter;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\SiteImportService;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Capell\MigrationAssistant\Tests\Fixtures\RecordingImportSessionExecutor;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('executes import handoffs only after the enclosing transaction commits', function (string $handoff, bool $commit): void {
    config()->set('migration-assistant.queue.connection', 'sync');
    config()->set('queue.connections.sync.after_commit', false);

    $executor = new RecordingImportSessionExecutor;
    resolve(ImportSessionExecutorRegistry::class)->register($executor);
    $status = match ($handoff) {
        'retry' => ImportSessionStatus::Failed,
        'reclaim' => ImportSessionStatus::Running,
        default => ImportSessionStatus::Queued,
    };
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(),
        'kind' => ImportSessionKind::PageImport, 'status' => $status,
        'source_environment' => 'handoff-test',
    ]);
    $session->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();
    $startingLevel = DB::transactionLevel();
    DB::beginTransaction();

    try {
        match ($handoff) {
            'retry' => RetryImportSessionAction::run($session),
            'reclaim' => ReclaimStaleImportSessionsAction::run(30, 10),
            default => dispatch(new ExecuteImportPlanJob(executeImportPlanSessionKey($session))),
        };

        expect($executor->executions)->toBe(0)
            ->and($session->refresh()->status)->toBe(ImportSessionStatus::Queued);

        if ($commit) {
            DB::commit();
            expect($executor->executions)->toBe(1)
                ->and($session->refresh()->status)->toBe(ImportSessionStatus::Completed);
            dispatch(new ExecuteImportPlanJob(executeImportPlanSessionKey($session)));
            expect($executor->executions)->toBe(1);
        } else {
            DB::rollBack();
            DB::transaction(static function (): void {});
            expect($executor->executions)->toBe(0)
                ->and($session->refresh()->status)->toBe($status);
        }
    } finally {
        if (DB::transactionLevel() > $startingLevel) {
            DB::rollBack($startingLevel);
        }
    }
})->with(['initial', 'retry', 'reclaim'])->with([true, false]);

it('expires overlap locks before stale import recovery can redispatch an interrupted worker', function (): void {
    $job = new ExecuteImportPlanJob(42);
    $middleware = $job->middleware()[0];
    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class);
    assert($middleware instanceof WithoutOverlapping);
    expect($middleware->expiresAfter)->toBeGreaterThan($job->timeout)
        ->toBeLessThanOrEqual(20 * 60);
});

it('preserves terminal imports when execution callbacks throw after completion', function (int $attempt): void {
    Notification::fake();
    $executor = new RecordingImportSessionExecutor;
    $executor->failAfterCompletion = true;

    resolve(ImportSessionExecutorRegistry::class)->register($executor);
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(),
        'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Queued,
        'source_environment' => 'handoff-test',
    ]);
    $job = new ExecuteImportPlanJob(executeImportPlanSessionKey($session));
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queueJob);

    expect(fn () => $job->handle(resolve(PackageReader::class), resolve(PageImportService::class), resolve(MediaIngestService::class)))
        ->toThrow(RuntimeException::class, 'Completion callback failed');
    $job->failed(new RuntimeException('Late worker failure'));

    expect($session->refresh()->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->failure_reason)->toBeNull()
        ->and($executor->executions)->toBe(1);
})->with([1, 3]);

it('recovers queued import handoffs after broker refusal or death following state commit', function (bool $brokerRefused): void {
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => $brokerRefused ? ImportSessionStatus::Running : ImportSessionStatus::Queued,
        'source_package_path' => 'migration-assistant/imports/uploads/test-session/stale.zip',
    ]);
    $session->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();

    if ($brokerRefused) {
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Broker refused'));
        expect(fn () => ReclaimStaleImportSessionsAction::run(30, 10))->toThrow(RuntimeException::class, 'Broker refused');
        $this->travel(31)->minutes();
    }

    Bus::fake();
    expect(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(1)
        ->and(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(0)
        ->and($session->refresh()->status)->toBe(ImportSessionStatus::Queued);
    Bus::assertDispatchedTimes(ExecuteImportPlanJob::class, 1);
})->with([true, false]);

it('recovers a refused retry action handoff from its committed queued state', function (): void {
    Storage::fake('local');
    config()->set('migration-assistant.disk', 'local');
    $path = 'migration-assistant/imports/uploads/test-session/retry.zip';
    Storage::disk('local')->put($path, 'retained archive');
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Failed, 'source_package_path' => $path,
        'resolution_map' => [], 'page_decisions' => [], 'relation_decisions' => [],
    ]);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Broker refused'));
    expect(function () use ($session): void {
        RetryImportSessionAction::run($session);
    })->toThrow(RuntimeException::class, 'Broker refused');

    Bus::fake();
    $this->travel(31)->minutes();
    expect(BuildImportRecoveryStatusAction::run()->staleCount)->toBe(1)
        ->and(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(1)
        ->and($session->refresh()->status)->toBe(ImportSessionStatus::Queued);
    Bus::assertDispatchedTimes(ExecuteImportPlanJob::class, 1);
});

it('lets only one recovery runner claim the same stale queued import snapshot', function (): void {
    Bus::fake();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued, 'source_package_path' => 'retained.zip',
    ]);
    $session->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    throw_if($dispatcher === null, RuntimeException::class, 'The recovery interleaving fixture requires an event dispatcher.');
    $connection->setEventDispatcher(clone $dispatcher);
    $interleaved = false;
    $connection->listen(function (QueryExecuted $query) use (&$interleaved): void {
        if (! $interleaved && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'import_sessions')) {
            $interleaved = true;
            expect(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(1);
        }
    });
    try {
        expect(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(0);
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }

    expect($interleaved)->toBeTrue();
    Bus::assertDispatchedTimes(ExecuteImportPlanJob::class, 1);
});

it('dispatches on the configured migration-assistant queue', function (): void {
    Queue::fake();

    dispatch(new ExecuteImportPlanJob(42));

    $queueName = config('migration-assistant.queue.name');
    Queue::assertPushedOn(
        is_string($queueName) ? $queueName : 'migration-assistant',
        ExecuteImportPlanJob::class,
    );
});

it('allows queued import execution to retry with backoff', function (): void {
    $job = new ExecuteImportPlanJob(42);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300]);
});

it('marks the session failed when source path is empty', function (): void {
    Notification::fake();

    $initiator = User::factory()->create();
    Auth::logout();

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => '',
    ]);

    new ExecuteImportPlanJob((int) $session->getKey())->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );

    $session->refresh();
    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toContain('source package')
        ->and($session->getAttribute('updated_by'))->toBeNull()
        ->and(Auth::id())->toBeNull();
});

it('fails safely when the initiator is deleted after dispatch', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $initiator = migrationAssistantGlobalQueueActor();
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/deleted-actor.zip';
    writePageImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), (int) $site->getKey());

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
    ]);

    $initiator->delete();

    executeImportPlanJob($session);

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe(__('migration-assistant::imports.execution_actor_missing'));
});

it('does not invoke a registered import executor after its queued actor is revoked', function (): void {
    Notification::fake();

    $actor = User::factory()->create();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $actor->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_environment' => 'executor-requires-actor',
        'source_package_path' => 'migration-assistant/imports/uploads/test-session/executor.zip',
    ]);
    $actor->delete();
    $wasExecuted = false;
    $executor = new readonly class(function () use (&$wasExecuted): void {
        $wasExecuted = true;
    }) implements ImportSessionExecutor
    {
        public function __construct(private Closure $onExecute) {}

        public function supports(ImportSession $session): bool
        {
            return $session->source_environment === 'executor-requires-actor';
        }

        public function canRetry(ImportSession $session): bool
        {
            return true;
        }

        public function execute(ImportSession $session): void
        {
            ($this->onExecute)();
        }
    };
    $registry = new ImportSessionExecutorRegistry;
    $registry->register($executor);

    new ExecuteImportPlanJob((int) $session->getKey())->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
        $registry,
    );

    expect($wasExecuted)->toBeFalse()
        ->and($session->refresh()->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe(__('migration-assistant::imports.execution_actor_missing'));
});

it('fails safely when the import permission is revoked after dispatch', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $initiator = migrationAssistantGlobalQueueActor();
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/revoked-permission.zip';
    writePageImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), (int) $site->getKey());

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::SiteImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
    ]);

    $initiator->revokePermissionTo(MigrationAssistantPermission::PageImport->value);
    $initiator->removeRole('super_admin');

    executeImportPlanJob($session);

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe(__('migration-assistant::imports.execution_permission_revoked'))
        ->and(Page::query()->withoutGlobalScopes()->where('name', 'Queued authorization test page')->exists())->toBeFalse();
});

it('fails safely when the initiator loses target site access after dispatch', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $permission = Permission::findOrCreate(MigrationAssistantPermission::PageImport->value);
    $role = Role::findOrCreate('migration-assistant-site-importer');
    $role->syncPermissions([$permission]);

    $initiator = User::factory()->create();
    $initiator->assignRoleForSite($site, $role);

    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/revoked-site-access.zip';
    writePageImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), (int) $site->getKey());

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
    ]);

    $initiator->removeRoleForSite($site, $role);

    executeImportPlanJob($session);

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe(__('migration-assistant::imports.execution_site_access_revoked'));
});

it('fails safely when the persisted import target has drifted', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $initiator = migrationAssistantGlobalQueueActor();
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/target-drift.zip';
    writePageImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), (int) $site->getKey());
    app()->instance(PageImportTargetResolver::class, new class implements PageImportTargetResolver
    {
        public function create(string $name): PageImportTargetData
        {
            return new PageImportTargetData(type: 'workspace', id: 2);
        }

        public function resolve(ImportSession $session): PageImportTargetData
        {
            return new PageImportTargetData(type: 'workspace', id: 2);
        }
    });

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'target_type' => 'workspace',
        'target_id' => 1,
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
    ]);

    executeImportPlanJob($session);

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe(__('migration-assistant::imports.execution_target_drifted'));
});

it('marks running sessions failed when the worker reports a job failure', function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('migration-assistant.disk', 'local');
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/running.zip';
    Storage::disk('local')->put($archiveRelativePath, 'retained archive');

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Running,
        'source_package_path' => $archiveRelativePath,
    ]);

    new ExecuteImportPlanJob((int) $session->getKey())->failed(new RuntimeException('worker timed out'));

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe('worker timed out')
        ->and(Storage::disk('local')->exists($archiveRelativePath))->toBeTrue();
});

it('requeues stale running sessions abandoned by terminated workers', function (): void {
    Queue::fake();

    $stale = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Running,
        'source_package_path' => 'migration-assistant/imports/uploads/test-session/stale.zip',
    ]);
    $stale->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();

    $recent = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Running,
        'source_package_path' => 'migration-assistant/imports/uploads/test-session/recent.zip',
    ]);

    $status = BuildImportRecoveryStatusAction::run();

    expect($status->staleCount)->toBe(1)
        ->and($status->oldestAgeMinutes)->toBeGreaterThanOrEqual(30)
        ->and(ReclaimStaleImportSessionsAction::run(30, 10))->toBe(1)
        ->and($stale->refresh()->status)->toBe(ImportSessionStatus::Queued)
        ->and($recent->refresh()->status)->toBe(ImportSessionStatus::Running);

    Queue::assertPushed(ExecuteImportPlanJob::class, fn (ExecuteImportPlanJob $job): bool => $job->importSessionId === $stale->getKey());
});

it('does not execute sessions that are no longer queued', function (): void {
    Notification::fake();

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_package_path' => '',
        'result_summary' => ['pages_imported' => 1],
    ]);

    new ExecuteImportPlanJob((int) $session->getKey())->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->failure_reason)->toBeNull()
        ->and($session->result_summary['pages_imported'] ?? null)->toBe(1);
});

it('retains partial import recovery data and records every created model', function (): void {
    Notification::fake();
    Storage::fake('local');
    config()->set('migration-assistant.disk', 'local');

    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $layoutId = migrationAssistantImportModelKey($layout);
    $typeId = migrationAssistantImportModelKey($type);
    $siteId = migrationAssistantImportModelKey($site);
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/partial-page-import.zip';
    $validDescriptor = pageImportJobDescriptor($layout, $type, $site, 901, 'Recoverable page');
    $brokenDescriptor = pageImportJobDescriptor($layout, $type, $site, 902, null);
    writeImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), [
        'pages/valid.json' => json_encode($validDescriptor, JSON_THROW_ON_ERROR),
        'pages/broken.json' => json_encode($brokenDescriptor, JSON_THROW_ON_ERROR),
    ]);
    $initiator = migrationAssistantGlobalQueueActor();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_filename' => 'partial-page-import.zip',
        'source_package_path' => $archiveRelativePath,
        'source_package_checksum' => 'sha256-partial-import',
        'resolution_map' => [
            'resolved' => [
                'layout:' . $layoutId => ['local_id' => $layoutId, 'strategy' => 'key'],
                'type:' . $typeId => ['local_id' => $typeId, 'strategy' => 'key'],
                'site:' . $siteId => ['local_id' => $siteId, 'strategy' => 'key'],
            ],
            'unresolved' => [],
        ],
        'page_decisions' => [],
        'relation_decisions' => [],
    ]);

    executeImportPlanJob($session);

    $session->refresh();
    $resultSummary = $session->result_summary;
    throw_unless(is_array($resultSummary), RuntimeException::class, 'Expected a partial import result summary.');
    $createdPageIds = $resultSummary['created_page_ids'] ?? null;
    throw_unless(is_array($createdPageIds), RuntimeException::class, 'Expected created page identifiers in the partial import result.');
    $createdPageId = $createdPageIds[0] ?? null;
    throw_unless(is_int($createdPageId), RuntimeException::class, 'Expected an integer created page identifier.');
    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $session->getKey())
        ->first();

    expect([
        'archive_retained' => Storage::disk('local')->exists($archiveRelativePath),
        'retry_eligible' => RetryImportSessionAction::canRetry($session),
        'rollback_report_created' => $rollbackReport instanceof ImportRollbackReport,
    ])->toBe([
        'archive_retained' => true,
        'retry_eligible' => false,
        'rollback_report_created' => true,
    ])
        ->and($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($resultSummary['pages_created'] ?? null)->toBe(1)
        ->and($resultSummary['errors'] ?? [])->toHaveCount(1)
        ->and(Page::query()->withoutGlobalScopes()->whereKey($createdPageId)->exists())->toBeTrue();

    throw_unless($rollbackReport instanceof ImportRollbackReport, RuntimeException::class, 'Expected rollback evidence for the partial import.');
    expect($rollbackReport->created_models)->toBe([
        ['class' => Page::class, 'id' => $createdPageId],
    ]);

    Queue::fake();

    expect(fn (): ImportSession => RetryImportSessionAction::run($session))
        ->toThrow(RuntimeException::class, 'Created content must be rolled back before this import session can be retried.');
    Queue::assertNothingPushed();

    Permission::findOrCreate(MigrationAssistantPermission::ImportSessionRollback->value);
    $initiator->givePermissionTo(MigrationAssistantPermission::ImportSessionRollback->value);
    $createdPage = Page::query()->withoutGlobalScopes()->findOrFail($createdPageId);
    $importedUpdatedAt = $createdPage->getAttribute('updated_at');
    throw_unless($importedUpdatedAt instanceof CarbonInterface, RuntimeException::class, 'Expected a page update timestamp.');
    $createdPage->forceFill(['updated_at' => $importedUpdatedAt->copy()->addSecond()])->saveQuietly();

    $skippedRollback = ExecuteImportRollbackAction::run($rollbackReport, $initiator);

    expect($skippedRollback->deleted)->toBe(0)
        ->and($skippedRollback->skipped[0]['reason'] ?? null)->toBe('edited_after_import')
        ->and(RetryImportSessionAction::canRetry($session->refresh()))->toBeFalse();

    $createdPage->forceFill(['updated_at' => $importedUpdatedAt])->saveQuietly();
    $rollback = ExecuteImportRollbackAction::run($rollbackReport->refresh(), $initiator);

    expect($rollback->deleted)->toBe(1)
        ->and(Page::query()->whereKey($createdPageId)->exists())->toBeFalse()
        ->and(RetryImportSessionAction::canRetry($session->refresh()))->toBeTrue();

    $retried = RetryImportSessionAction::run($session);

    expect($retried->status)->toBe(ImportSessionStatus::Queued)
        ->and(ImportRollbackReport::query()->where('import_session_id', $session->getKey())->count())->toBe(1);
    Queue::assertPushed(ExecuteImportPlanJob::class, 1);
});

it('blocks archive replay when created result identifiers have no matching rollback evidence', function (string $field, bool $olderReport, bool $staleSnapshot): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('migration-assistant.disk', 'local');
    $path = 'migration-assistant/imports/uploads/test-session/missing-report.zip';
    Storage::disk('local')->put($path, 'retained archive');
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Failed,
        'source_package_path' => $path,
        'resolution_map' => [], 'page_decisions' => [], 'relation_decisions' => [],
        'result_summary' => [],
    ]);
    $currentSession = ImportSession::query()->findOrFail(executeImportPlanSessionKey($session));
    $currentSession->forceFill([
        'result_summary' => [$field => [42], 'errors' => ['Interrupted before rollback report persistence']],
    ])->save();

    if (! $staleSnapshot) {
        $session->refresh();
    }

    if ($olderReport) {
        ImportRollbackReport::query()->create([
            'uuid' => (string) Str::uuid(),
            'import_session_id' => $session->getKey(),
            'created_models' => [['class' => Page::class, 'id' => 41]],
            'summary' => ['rollback_execution' => ['deleted' => 1, 'skipped' => []]],
        ]);
    }

    expect(RetryImportSessionAction::canRetry($session))->toBeFalse();
    expect(fn (): ImportSession => RetryImportSessionAction::run($session))
        ->toThrow(RuntimeException::class, 'Created content must be rolled back before this import session can be retried.');
    expect($session->refresh()->status)->toBe(ImportSessionStatus::Failed)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
    Queue::assertNothingPushed();
})->with(['created_page_ids', 'created_site_ids', 'created_site_domain_ids'])->with([false, true])->with([false, true]);

it('executes site import sessions with unresolved site refs that are created from the package', function (): void {
    Notification::fake();

    $language = Language::factory()->english()->create();
    $siteType = Blueprint::factory()->site()->create();
    $theme = Theme::factory()->create();
    $layout = Layout::factory()->create();
    $pageType = Blueprint::factory()->page()->create();
    $sourceSiteId = 991;
    $sourcePageId = 992;
    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/site-job-test.zip';
    $archiveAbsolutePath = Storage::disk('local')->path($archiveRelativePath);

    writeImportPackageForJob($archiveAbsolutePath, [
        'relations/sites/source.json' => json_encode([
            'type' => 'site',
            'ref' => 'site:' . $sourceSiteId,
            'id' => $sourceSiteId,
            'attributes' => [
                'name' => 'Queued Imported Site',
                'blueprint_id' => $siteType->getKey(),
                'theme_id' => $theme->getKey(),
                'language_id' => $language->getKey(),
                'status' => true,
                'default' => false,
            ],
        ], JSON_THROW_ON_ERROR),
        'relations/site-domains/source.json' => json_encode([
            'type' => 'site-domain',
            'ref' => 'site-domain:993',
            'id' => 993,
            'attributes' => [
                'site_id' => $sourceSiteId,
                'domain' => 'queued-import.test',
                'language_id' => $language->getKey(),
                'path' => '/',
                'scheme' => 'https',
                'status' => true,
                'default' => true,
            ],
        ], JSON_THROW_ON_ERROR),
        'pages/source.json' => json_encode([
            'type' => 'page',
            'uuid' => (string) Str::uuid(),
            'id' => $sourcePageId,
            'attributes' => [
                'id' => $sourcePageId,
                'uuid' => (string) Str::uuid(),
                'name' => 'Queued Imported Page',
                'layout_id' => $layout->getKey(),
                'blueprint_id' => $pageType->getKey(),
                'site_id' => $sourceSiteId,
                'parent_id' => null,
            ],
            'owned_relations' => ['page_urls' => []],
            'shared_relations' => [
                'layout' => ['ref' => 'layout:' . $layout->getKey()],
                'type' => ['ref' => 'type:' . $pageType->getKey()],
                'site' => ['ref' => 'site:' . $sourceSiteId],
            ],
            'media_bindings' => [],
        ], JSON_THROW_ON_ERROR),
    ]);

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => migrationAssistantGlobalQueueActor()->getKey(),
        'kind' => ImportSessionKind::SiteImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
        'resolution_map' => [
            'resolved' => [
                'layout:' . $layout->getKey() => ['local_id' => $layout->getKey(), 'strategy' => 'key'],
                'type:' . $pageType->getKey() => ['local_id' => $pageType->getKey(), 'strategy' => 'key'],
            ],
            'unresolved' => ['site:' . $sourceSiteId],
        ],
    ]);

    new ExecuteImportPlanJob((int) $session->getKey())->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );

    $session->refresh();
    $site = Site::query()->where('name', 'Queued Imported Site')->firstOrFail();
    $siteDomain = SiteDomain::query()->where('domain', 'queued-import.test')->firstOrFail();
    $page = Page::query()->withoutGlobalScopes()->where('name', 'Queued Imported Page')->firstOrFail();
    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $session->getKey())
        ->firstOrFail();

    expect($session->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->result_summary['pages_created'] ?? null)->toBe(1)
        ->and($session->result_summary['created_site_ids'] ?? [])->toBe([$site->getKey()])
        ->and($session->result_summary['created_site_domain_ids'] ?? [])->toBe([$siteDomain->getKey()])
        ->and($rollbackReport->summary['created_site_ids'] ?? [])->toBe([$site->getKey()])
        ->and($rollbackReport->summary['created_site_domain_ids'] ?? [])->toBe([$siteDomain->getKey()])
        ->and((int) $page->getAttribute('site_id'))->toBe((int) $site->getKey())
        ->and(Storage::disk('local')->exists($archiveRelativePath))->toBeFalse();
});

it('fails site import sessions when malformed site relation refs mask unresolved page refs', function (): void {
    Notification::fake();

    $archiveRelativePath = 'migration-assistant/imports/uploads/test-session/malformed-site-ref-job-test.zip';
    $archiveAbsolutePath = Storage::disk('local')->path($archiveRelativePath);

    writeImportPackageForJob($archiveAbsolutePath, [
        'relations/sites/source.json' => json_encode([
            'type' => 'site',
            'ref' => 'page:999',
            'id' => 999,
            'attributes' => [
                'name' => 'Malformed Site Relation',
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => migrationAssistantGlobalQueueActor()->getKey(),
        'kind' => ImportSessionKind::SiteImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
        'resolution_map' => [
            'resolved' => [],
            'unresolved' => ['page:999'],
        ],
    ]);

    $failedForUnresolvedRefs = false;

    try {
        new ExecuteImportPlanJob(executeImportPlanSessionKey($session))->handle(
            resolve(PackageReader::class),
            resolve(PageImportService::class),
            resolve(MediaIngestService::class),
            resolve(SiteImportService::class),
        );
    } catch (RuntimeException $runtimeException) {
        $failedForUnresolvedRefs = true;

        expect($runtimeException->getMessage())->toContain('unresolved references');
    }

    $session->refresh();

    expect($failedForUnresolvedRefs)->toBeTrue()
        ->and($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toContain('unresolved references')
        ->and(Storage::disk('local')->exists($archiveRelativePath))->toBeTrue();
});

/**
 * @param  array<string, string>  $payload
 */
function writeImportPackageForJob(string $archivePath, array $payload): void
{
    $manifest = new PackageManifest(
        packageType: PackageType::SiteExport,
        capellVersion: app()->version(),
        exportedAt: CarbonImmutable::now('UTC'),
        sourceEnvironment: 'testing',
        sourceLiveVersionId: null,
        pageCount: 1,
        siteCount: 1,
        relationCounts: [],
    );

    (new PackageWriter)->write(
        $archivePath,
        $manifest,
        new DependencyGraph([], [], [], []),
        $payload,
        [],
    );
}

function writePageImportPackageForJob(string $archivePath, int $siteId): void
{
    writeImportPackageForJob($archivePath, [
        'pages/authorization-test.json' => json_encode([
            'type' => 'page',
            'id' => 1,
            'attributes' => [
                'name' => 'Queued authorization test page',
                'site_id' => $siteId,
            ],
            'shared_relations' => [],
        ], JSON_THROW_ON_ERROR),
    ]);
}

/**
 * @return array<string, mixed>
 */
function pageImportJobDescriptor(Layout $layout, Blueprint $type, Site $site, int $sourceId, ?string $name): array
{
    $layoutId = migrationAssistantImportModelKey($layout);
    $typeId = migrationAssistantImportModelKey($type);
    $siteId = migrationAssistantImportModelKey($site);
    $attributes = [
        'id' => $sourceId,
        'uuid' => (string) Str::uuid(),
        'layout_id' => $layoutId,
        'blueprint_id' => $typeId,
        'site_id' => $siteId,
        'parent_id' => null,
    ];

    if ($name !== null) {
        $attributes['name'] = $name;
    }

    return [
        'type' => 'page',
        'id' => $sourceId,
        'attributes' => $attributes,
        'owned_relations' => ['page_urls' => []],
        'shared_relations' => [
            'layout' => ['ref' => 'layout:' . $layoutId],
            'type' => ['ref' => 'type:' . $typeId],
            'site' => ['ref' => 'site:' . $siteId],
        ],
        'media_bindings' => [],
    ];
}

function migrationAssistantImportModelKey(Model $model): int
{
    $key = $model->getKey();

    if (! is_int($key)) {
        throw new LogicException('Expected an integer migration import model key.');
    }

    return $key;
}

function migrationAssistantGlobalQueueActor(): User
{
    Permission::findOrCreate(MigrationAssistantPermission::PageImport->value);
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo(MigrationAssistantPermission::PageImport->value);

    return $actor->fresh();
}

function executeImportPlanJob(ImportSession $session): void
{
    new ExecuteImportPlanJob(executeImportPlanSessionKey($session))->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );
}

function executeImportPlanSessionKey(ImportSession $session): int
{
    $key = $session->getKey();

    if (! is_int($key)) {
        throw new LogicException('Expected an integer import session key.');
    }

    return $key;
}
