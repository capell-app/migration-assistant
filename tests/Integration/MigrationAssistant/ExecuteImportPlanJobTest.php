<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
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
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

    (new ExecuteImportPlanJob((int) $session->getKey()))->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );

    $session->refresh();
    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toContain('source package')
        ->and((int) $session->getAttribute('updated_by'))->toBe((int) $initiator->getKey())
        ->and(Auth::id())->toBeNull();
});

it('fails safely when the initiator is deleted after dispatch', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $initiator = migrationAssistantGlobalQueueActor();
    $archiveRelativePath = 'migration-assistant/imports/deleted-actor.zip';
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

it('fails safely when the import permission is revoked after dispatch', function (): void {
    Notification::fake();

    $site = Site::factory()->create();
    $initiator = migrationAssistantGlobalQueueActor();
    $archiveRelativePath = 'migration-assistant/imports/revoked-permission.zip';
    writePageImportPackageForJob(Storage::disk('local')->path($archiveRelativePath), (int) $site->getKey());

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $initiator->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Queued,
        'source_package_path' => $archiveRelativePath,
    ]);

    $initiator->revokePermissionTo(MigrationAssistantPermission::PageImport->value);

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
    $archiveRelativePath = 'migration-assistant/imports/revoked-site-access.zip';
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
    $archiveRelativePath = 'migration-assistant/imports/target-drift.zip';
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

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Running,
        'source_package_path' => 'migration-assistant/imports/running.zip',
    ]);

    (new ExecuteImportPlanJob((int) $session->getKey()))->failed(new RuntimeException('worker timed out'));

    $session->refresh();

    expect($session->status)->toBe(ImportSessionStatus::Failed)
        ->and($session->failure_reason)->toBe('worker timed out');
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

    (new ExecuteImportPlanJob((int) $session->getKey()))->handle(
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

it('executes site import sessions with unresolved site refs that are created from the package', function (): void {
    Notification::fake();

    $language = Language::factory()->english()->create();
    $siteType = Blueprint::factory()->site()->create();
    $theme = Theme::factory()->create();
    $layout = Layout::factory()->create();
    $pageType = Blueprint::factory()->page()->create();
    $sourceSiteId = 991;
    $sourcePageId = 992;
    $archiveRelativePath = 'migration-assistant/imports/site-job-test.zip';
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

    (new ExecuteImportPlanJob((int) $session->getKey()))->handle(
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
        ->and((int) $page->getAttribute('site_id'))->toBe((int) $site->getKey());
});

it('fails site import sessions when malformed site relation refs mask unresolved page refs', function (): void {
    Notification::fake();

    $archiveRelativePath = 'migration-assistant/imports/malformed-site-ref-job-test.zip';
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
        (new ExecuteImportPlanJob((int) $session->getKey()))->handle(
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
        ->and($session->failure_reason)->toContain('unresolved references');
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

function migrationAssistantGlobalQueueActor(): User
{
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');
    $actor->givePermissionTo(MigrationAssistantPermission::PageImport->value);

    return $actor->fresh();
}

function executeImportPlanJob(ImportSession $session): void
{
    (new ExecuteImportPlanJob((int) $session->getKey()))->handle(
        resolve(PackageReader::class),
        resolve(PageImportService::class),
        resolve(MediaIngestService::class),
        resolve(SiteImportService::class),
    );
}
