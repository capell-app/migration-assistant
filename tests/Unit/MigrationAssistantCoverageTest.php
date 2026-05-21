<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\Imports\AdvancePageImportToValidationAction;
use Capell\MigrationAssistant\Actions\Imports\DispatchPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\RefreshPageImportStatusAction;
use Capell\MigrationAssistant\Actions\Imports\ResolvePageImportConfirmationTargetAction;
use Capell\MigrationAssistant\Actions\Imports\ResolvePageImportSessionAction;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantRowContributor;
use Capell\MigrationAssistant\Contracts\NullPageCollisionDetector;
use Capell\MigrationAssistant\Contracts\NullPageImportTargetResolver;
use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\ExportOptions;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\ImportValidationSummary;
use Capell\MigrationAssistant\Data\PageImportTargetData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Exceptions\NotImplementedException;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\ImportSessionResource;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\Pages\ListImportSessions;
use Capell\MigrationAssistant\Filament\Resources\ImportSessions\Tables\ImportSessionsTable;
use Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Notifications\ImportCompletedNotification;
use Capell\MigrationAssistant\Notifications\ImportFailedNotification;
use Capell\MigrationAssistant\Policies\ImportSessionPolicy;
use Capell\MigrationAssistant\Services\Import\FieldMapper;
use Capell\MigrationAssistant\Services\Import\ManifestValidationReport;
use Capell\MigrationAssistant\Services\Import\Resolvers\KeyedMatchResolver;
use Capell\MigrationAssistant\Services\Import\SpreadsheetReader;
use Capell\MigrationAssistant\Support\ImportTargetRegistry;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;

uses(CreatesAdminUser::class);

it('resolves import confirmation target from a single mapped site', function (): void {
    $site = Site::factory()->create(['name' => 'Mapped Site']);
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Validated,
        'source_filename' => 'mapped.zip',
        'resolution_map' => [
            'resolved' => [
                'site:' . $site->getKey() => ['local_id' => (string) $site->getKey()],
            ],
        ],
    ]);

    expect(ResolvePageImportConfirmationTargetAction::run($session))->toBe('Mapped Site');
});

it('falls back to the configured target resolver label for ambiguous sites', function (): void {
    app()->bind(PageImportTargetResolver::class, function (): object {
        return new class implements PageImportTargetResolver
        {
            public function create(string $name): PageImportTargetData
            {
                return new PageImportTargetData(type: 'workspace', id: 99, label: $name, url: 'https://example.test/imports');
            }

            public function resolve(ImportSession $session): PageImportTargetData
            {
                return new PageImportTargetData(type: 'workspace', id: 99, label: 'Resolver Target', url: 'https://example.test/imports');
            }
        };
    });

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Validated,
        'source_filename' => 'fallback.zip',
        'resolution_map' => [
            'resolved' => [
                'site:1' => ['local_id' => 1],
                'site:2' => ['local_id' => 2],
            ],
        ],
    ]);

    expect(ResolvePageImportConfirmationTargetAction::run($session))->toBe('Resolver Target');
});

it('keeps dispatch on validate when no session can be resolved', function (): void {
    $status = DispatchPageImportAction::run(
        sessionId: null,
        validationSummary: [],
        confirmation: '',
        confirmationExpected: '',
    );

    expect($status->step)->toBe('validate');
});

it('declares migration assistant admin pages tables and health compatibility', function (): void {
    expect(ImportSitesPage::getNavigationLabel())->toBeString()
        ->and((new ImportSitesPage)->getTitle())->toBeString()
        ->and(ImportSessionResource::getModel())->toBe(ImportSession::class)
        ->and(ImportSessionResource::getPages())->toHaveKeys(['index', 'view'])
        ->and(MigrationAssistantHealthCheck::compatibleCapellApiVersion())->toBe('^4.0');
});

it('keeps relation resolution blocked until required decisions are usable', function (): void {
    $decisionData = new PageImportDecisionData(
        sessionId: 123,
        reviewRows: [],
        pageDecisions: [],
        resolveRows: [
            [
                'ref' => 'site:10',
                'top_match' => ['local_id' => 10],
                'alternatives' => [['local_id' => 11]],
            ],
        ],
        relationDecisions: [
            'site:10' => [
                'action' => RelationResolveRow::ACTION_USE_EXISTING,
                'target_id' => '',
            ],
        ],
        canUpdateSharedRelations: true,
    );

    $state = AdvancePageImportToValidationAction::run($decisionData, true);

    expect($state->step)->toBe('resolve')
        ->and($state->notice)->toBe($state::NOTICE_BLOCKED_PENDING_DECISIONS);
});

it('blocks non-skip decisions for workspace url conflicts before validation', function (): void {
    $decisionData = new PageImportDecisionData(
        sessionId: 456,
        reviewRows: [
            [
                'uuid' => 'page-one',
                'collision_state' => PageReviewRow::COLLISION_URL_WORKSPACE,
            ],
        ],
        pageDecisions: [
            'page-one' => ['action' => PageReviewRow::ACTION_CREATE],
        ],
        resolveRows: [],
        relationDecisions: [],
        canUpdateSharedRelations: false,
    );

    $state = AdvancePageImportToValidationAction::run($decisionData, true);

    expect($state->step)->toBe('review')
        ->and($state->notice)->toBe($state::NOTICE_BLOCKED_BY_WORKSPACE_CONFLICT);
});

it('resolves page import status targets and terminal status flags', function (): void {
    $this->actingAsAdmin();

    app()->bind(PageImportTargetResolver::class, fn (): NullPageImportTargetResolver => new NullPageImportTargetResolver);

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => auth()->id(),
        'target_type' => 'workspace',
        'target_id' => 987,
        'target_label' => 'Imported Workspace',
        'target_url' => 'https://example.test/workspace',
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'status.zip',
        'result_summary' => ['pages_imported' => 2],
    ]);

    expect(ResolvePageImportSessionAction::run((int) $session->getKey()))->toBeInstanceOf(ImportSession::class);

    $status = RefreshPageImportStatusAction::run((int) $session->getKey(), null);

    expect($status->step)->toBe('completed')
        ->and($status->targetId)->toBe(987)
        ->and($status->targetUrl)->toBe('https://example.test/workspace')
        ->and($status->resultSummary)->toBe(['pages_imported' => 2])
        ->and(ImportSessionStatus::Completed->isTerminal())->toBeTrue()
        ->and(ImportSessionStatus::Failed->isTerminal())->toBeTrue()
        ->and(ImportSessionStatus::Abandoned->isTerminal())->toBeTrue()
        ->and(ImportSessionStatus::Running->isTerminal())->toBeFalse();
});

it('exposes null import target and collision defaults', function (): void {
    $resolver = new NullPageImportTargetResolver;
    $target = $resolver->create('');
    $session = new ImportSession;
    $session->forceFill([
        'target_type' => '',
        'target_id' => null,
        'target_label' => '',
        'target_url' => '',
    ]);

    expect($target->type)->toBe('live')
        ->and($target->label)->toBeNull()
        ->and($resolver->resolve($session)->type)->toBe('live')
        ->and((new NullPageCollisionDetector)->detect(['/demo'], null))
        ->toBe([PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE]);
});

it('declares import session table columns and summary formatting', function (): void {
    $reflection = new ReflectionMethod(ImportSessionsTable::class, 'getTableColumns');

    $columns = collect($reflection->invoke(null));
    $summaryColumn = $columns->first(
        fn (mixed $column): bool => $column instanceof TextColumn && $column->getName() === 'result_summary',
    );

    expect($columns)->not->toBeEmpty()
        ->and($summaryColumn)->toBeInstanceOf(TextColumn::class)
        ->and($summaryColumn->formatState([
            'pages_created' => 3,
            'relations_resolved' => 2,
            'media_reassigned' => 1,
            'created_site_ids' => [10, 11],
            'created_site_domain_ids' => [20],
        ]))->toBe('P:3 · R:2 · M:1 · S:2 · D:1')
        ->and($summaryColumn->formatState([]))->toBe('—');
});

it('keeps external preview, target registry, and notifications serializable', function (): void {
    config()->set('migration-assistant.notifications.channels', ['mail', 'database', 123]);

    $preview = new ExternalImportPreview(
        target: 'pages',
        creates: 2,
        skips: 1,
        rows: [['title' => 'Home']],
        errors: ['Missing URL'],
    );
    $registry = new ImportTargetRegistry;
    $registry->register('article', Site::class);

    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'complete.zip',
        'result_summary' => ['pages_created' => '3'],
    ]);

    $completed = new ImportCompletedNotification($session);
    $failed = new ImportFailedNotification($session, 'Checksum mismatch');

    expect($preview->toArray())->toMatchArray([
        'target' => 'pages',
        'creates' => 2,
        'skips' => 1,
        'rows' => [['title' => 'Home']],
        'errors' => ['Missing URL'],
    ])
        ->and($registry->all())->toHaveKey('article', Site::class)
        ->and($completed->via(new stdClass))->toBe(['mail', 'database'])
        ->and($completed->toArray(new stdClass))->toMatchArray([
            'import_session_id' => $session->getKey(),
            'outcome' => 'completed',
        ])
        ->and($completed->toMail(new stdClass)->introLines[0])->toContain('3')
        ->and($failed->via(new stdClass))->toBe(['mail', 'database'])
        ->and($failed->toArray(new stdClass))->toMatchArray([
            'failure_reason' => 'Checksum mismatch',
            'outcome' => 'failed',
        ]);
});

it('projects migration assistant DTOs to stable array and count contracts', function (): void {
    $pageRow = new PageReviewRow(
        uuid: 'page-home',
        title: 'Home',
        primaryUrl: '/home',
        resolvedSiteId: 12,
        siteRef: 'site:12',
        urls: [['site_id' => 12, 'language_id' => 1, 'url' => '/home']],
        collisionState: PageReviewRow::COLLISION_NONE,
        conflictMessages: [],
        suggestedAction: PageReviewRow::ACTION_CREATE,
    );
    $relationRow = new RelationResolveRow(
        group: RelationResolveRow::GROUP_SITES,
        ref: 'site:12',
        topMatch: ['local_id' => 12, 'strategy' => 'slug', 'confidence' => 0.98, 'reason' => 'Matched slug'],
        alternatives: [['local_id' => 13, 'strategy' => 'name', 'confidence' => 0.7, 'reason' => 'Matched name']],
        warnings: ['Review manually.'],
        suggestedAction: RelationResolveRow::ACTION_USE_EXISTING,
    );
    $summary = new ImportValidationSummary(
        pages: ['create' => 1, 'update' => 0, 'skip' => 0],
        relations: ['match' => 1, 'create' => 0, 'clone' => 0, 'update' => 0, 'skip' => 0],
        media: ['import' => 0, 'reuse' => 1],
        blockingErrors: [],
        warnings: ['Low confidence relation.'],
        generatedAt: '2026-05-20T12:00:00+00:00',
    );
    $readResult = new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title', 'url'],
        rows: [['title' => 'Home', 'url' => '/home']],
        metadata: ['filename' => 'pages.csv'],
    );

    expect($pageRow->toArray())->toMatchArray([
        'uuid' => 'page-home',
        'title' => 'Home',
        'primary_url' => '/home',
        'resolved_site_id' => 12,
        'site_ref' => 'site:12',
        'suggested_action' => PageReviewRow::ACTION_CREATE,
    ])
        ->and($relationRow->toArray())->toMatchArray([
            'group' => RelationResolveRow::GROUP_SITES,
            'ref' => 'site:12',
            'suggested_action' => RelationResolveRow::ACTION_USE_EXISTING,
        ])
        ->and($summary->isClean())->toBeTrue()
        ->and($summary->toArray())->toMatchArray([
            'pages' => ['create' => 1, 'update' => 0, 'skip' => 0],
            'warnings' => ['Low confidence relation.'],
            'generated_at' => '2026-05-20T12:00:00+00:00',
        ])
        ->and(ExportOptions::defaults())->toEqual(new ExportOptions)
        ->and($readResult->count())->toBe(1);
});

it('matches keyed resolver records by exact key then normalised name', function (): void {
    $keyedSite = Site::factory()->create(['name' => 'Existing Site']);
    $namedSite = Site::factory()->create(['name' => '  Legacy Workspace  ']);

    $keyResolver = new KeyedMatchResolver(Site::class, 'id', null);
    $nameResolver = new KeyedMatchResolver(Site::class, 'missing_key', 'name');

    $keyMatch = $keyResolver->resolve(['id' => (string) $keyedSite->getKey()]);
    $nameMatch = $nameResolver->resolve(['name' => 'legacy workspace']);

    expect($keyMatch)->not->toBeNull()
        ->and($keyMatch?->localId)->toBe($keyedSite->getKey())
        ->and($keyMatch?->strategy)->toBe('id')
        ->and($nameMatch)->not->toBeNull()
        ->and($nameMatch?->localId)->toBe($namedSite->getKey())
        ->and($nameMatch?->strategy)->toBe('name:normalised')
        ->and($nameMatch?->confidence)->toBe(0.7)
        ->and($keyResolver->resolve(['id' => '']))->toBeNull()
        ->and($nameResolver->resolve(['name' => 'Not Found']))->toBeNull();
});

it('delegates spreadsheet imports to csv reader and keeps unsupported stubs explicit', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'migration-assistant-csv-');
    expect($path)->toBeString();

    file_put_contents($path, "title,url\nHome,/home\nAbout,/about\n");

    try {
        $result = (new SpreadsheetReader)->read($path);
    } finally {
        @unlink($path);
    }

    expect($result->sourceType)->toBe('csv')
        ->and($result->columns)->toBe(['title', 'url'])
        ->and($result->rows)->toBe([
            ['title' => 'Home', 'url' => '/home'],
            ['title' => 'About', 'url' => '/about'],
        ])
        ->and(NotImplementedException::forPhase('H4', 'Site importer')->getMessage())
        ->toBe('Site importer is not implemented yet — tracked in phase H4.');
});

it('requires global admin status and import-session permission for policy reads', function (): void {
    $session = new ImportSession;
    $policy = new ImportSessionPolicy;
    $allowedUser = new class extends User
    {
        public function isGlobalAdmin(): bool
        {
            return true;
        }

        public function checkPermissionTo($permission, $guardName = null): bool
        {
            return $permission === MigrationAssistantPermission::ImportSessionView->value;
        }
    };
    $deniedUser = new class extends User
    {
        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function checkPermissionTo($permission, $guardName = null): bool
        {
            return true;
        }
    };

    expect($policy->viewAny($allowedUser))->toBeTrue()
        ->and($policy->view($allowedUser, $session))->toBeTrue()
        ->and($policy->viewAny($deniedUser))->toBeFalse()
        ->and($policy->view($deniedUser, $session))->toBeFalse()
        ->and($policy->create($allowedUser))->toBeFalse()
        ->and($policy->update($allowedUser, $session))->toBeFalse()
        ->and($policy->delete($allowedUser, $session))->toBeFalse();
});

it('covers remaining migration assistant support adapters and admin page accessors', function (): void {
    $site = Site::factory()->create(['name' => 'Scoped Site']);
    $contributor = new NullMigrationAssistantRowContributor;
    $query = Site::query()->whereKey($site->getKey());
    $validReport = new ManifestValidationReport(warnings: ['Optional metadata missing.']);
    $invalidReport = new ManifestValidationReport(errors: ['Schema version is unsupported.']);
    $listPage = new ListImportSessions;
    $getActions = new ReflectionMethod(ListImportSessions::class, 'getActions');

    expect($contributor->extraAttributes($site))->toBe([])
        ->and($contributor->normalizeIncomingRow(['name' => 'Imported']))->toBe(['name' => 'Imported'])
        ->and($contributor->scopeExportable($query))->toBe($query)
        ->and($validReport->isValid())->toBeTrue()
        ->and($validReport->toArray())->toBe([
            'errors' => [],
            'warnings' => ['Optional metadata missing.'],
        ])
        ->and($invalidReport->isValid())->toBeFalse()
        ->and(ListImportSessions::getResource())->toBe(ImportSessionResource::class)
        ->and($listPage->getSubheading())->toBeString()
        ->and($listPage->getSubNavigation())->toBeArray()
        ->and($getActions->invoke($listPage))->toBe([]);
});

it('maps external type fields and builds failed notification mail fallback URLs', function (): void {
    config()->set('app.url', 'https://capell.test');
    config()->set('migration-assistant.notifications.channels', 'not-an-array');

    $mappedType = (new FieldMapper)->map([
        'Title' => 'Landing Page',
        'slug' => 'landing-page',
        'Custom: Field' => 'Custom value',
    ], target: 'type');
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Failed,
        'source_filename' => 'failed.zip',
    ]);
    $notification = new ImportFailedNotification($session, 'Package checksum failed.');

    expect($mappedType)->toBe([
        'name' => 'Landing Page',
        'key' => 'landing-page',
        'meta' => ['imported' => ['custom__field' => 'Custom value']],
    ])
        ->and($notification->via(new stdClass))->toBe(['mail', 'database'])
        ->and($notification->toMail(new stdClass)->actionUrl)
        ->toContain('/admin/import-sessions/' . $session->getKey());
});
