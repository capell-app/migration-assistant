<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\MigrationAssistant\Actions\BuildImportValidationSummaryAction;
use Capell\MigrationAssistant\Actions\BuildPageReviewRowsAction;
use Capell\MigrationAssistant\Actions\Imports\AdvancePageImportToValidationAction;
use Capell\MigrationAssistant\Actions\Imports\BindMigrationArchiveUploadAction;
use Capell\MigrationAssistant\Actions\Imports\DispatchPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\RefreshPageImportStatusAction;
use Capell\MigrationAssistant\Actions\Imports\StartPageImportAction;
use Capell\MigrationAssistant\Contracts\PageCollisionDetector;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\Imports\PageImportStatusData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Capell\MigrationAssistant\Support\ChecksumGenerator;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('keeps import pages page local wizard state transitions deterministic', function (): void {
    $page = new ImportPagesPage;

    expect(ImportPagesPage::shouldRegisterNavigation())->toBeFalse()
        ->and(ImportPagesPage::getNavigationLabel())->toBeString()
        ->and($page->getTitle())->toBeString()
        ->and($page->getSubNavigation())->toBeArray()
        ->and($page->getProgressPercent())->toBe(0)
        ->and($page->getTargetWorkspaceUrl())->toBeNull()
        ->and($page->confirmationMatches())->toBeTrue()
        ->and($page->hasUpdateExistingRelationDecision())->toBeFalse();

    $page->sessionStatus = ImportSessionStatus::Queued->value;
    $page->targetUrl = 'https://example.test/workspace';
    $page->confirmationExpected = 'Main Workspace';
    $page->confirmation = ' main workspace ';
    $page->relationDecisions = [
        'site:1' => ['action' => RelationResolveRow::ACTION_UPDATE_EXISTING],
    ];

    expect($page->getProgressPercent())->toBe(5)
        ->and($page->getTargetWorkspaceUrl())->toBe('https://example.test/workspace')
        ->and($page->confirmationMatches())->toBeTrue()
        ->and($page->hasUpdateExistingRelationDecision())->toBeTrue();

    $page->sessionStatus = ImportSessionStatus::Running->value;
    expect($page->getProgressPercent())->toBe(50);

    $page->sessionStatus = ImportSessionStatus::Completed->value;
    expect($page->getProgressPercent())->toBe(100);

    $page->backToUpload();

    expect($page->step)->toBe(ImportPagesPage::STEP_UPLOAD)
        ->and($page->sessionId)->toBeNull()
        ->and($page->reviewRows)->toBe([])
        ->and($page->pageDecisions)->toBe([])
        ->and($page->resolveRows)->toBe([])
        ->and($page->relationDecisions)->toBe([])
        ->and($page->validationSummary)->toBe([])
        ->and($page->confirmation)->toBe('')
        ->and($page->confirmationExpected)->toBe('')
        ->and($page->targetUrl)->toBeNull();
});

it('applies import wizard and status DTOs to the page state', function (): void {
    $page = new ImportPagesPage;

    $page->sessionId = 123;
    fakeResidualImportAction(new AdvancePageImportToValidationAction, new PageImportWizardStateData(
        step: ImportPagesPage::STEP_VALIDATE,
        sessionId: 123,
        reviewRows: [['uuid' => 'page-home']],
        pageDecisions: ['page-home' => ['action' => PageReviewRow::ACTION_CREATE]],
        resolveRows: [['ref' => 'site:1']],
        relationDecisions: ['site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1]],
        validationSummary: ['blocking_errors' => []],
        confirmationExpected: 'Imported Workspace',
    ));

    $page->advanceToValidate();

    expect($page->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($page->sessionId)->toBe(123)
        ->and($page->reviewRows)->toBe([['uuid' => 'page-home']])
        ->and($page->pageDecisions)->toBe(['page-home' => ['action' => PageReviewRow::ACTION_CREATE]])
        ->and($page->resolveRows)->toBe([['ref' => 'site:1']])
        ->and($page->relationDecisions)->toBe(['site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1]])
        ->and($page->validationSummary)->toBe(['blocking_errors' => []])
        ->and($page->confirmationExpected)->toBe('Imported Workspace')
        ->and($page->confirmation)->toBe('');

    fakeResidualImportAction(new RefreshPageImportStatusAction, new PageImportStatusData(
        step: ImportPagesPage::STEP_COMPLETED,
        sessionStatus: ImportSessionStatus::Completed->value,
        resultSummary: ['pages_created' => 2],
        targetId: 88,
        targetUrl: 'https://example.test/imported',
    ));

    $page->step = ImportPagesPage::STEP_EXECUTING;
    $page->refreshStatus();

    expect($page->step)->toBe(ImportPagesPage::STEP_COMPLETED)
        ->and($page->sessionStatus)->toBe(ImportSessionStatus::Completed->value)
        ->and($page->resultSummary)->toBe(['pages_created' => 2])
        ->and($page->failureReason)->toBeNull()
        ->and($page->targetId)->toBe(88)
        ->and($page->targetUrl)->toBe('https://example.test/imported');
});

it('backs from resolve to the correct previous wizard step', function (): void {
    $page = new ImportPagesPage;
    $page->resolveRows = [];

    $page->backToResolve();

    expect($page->step)->toBe(ImportPagesPage::STEP_REVIEW);

    $page->resolveRows = [
        [
            'ref' => 'site:1',
            'top_match' => ['local_id' => 1],
            'alternatives' => [['local_id' => 2]],
        ],
    ];

    $page->backToResolve();

    expect($page->step)->toBe(ImportPagesPage::STEP_RESOLVE);

    $page->backToReview();

    expect($page->step)->toBe(ImportPagesPage::STEP_REVIEW);
});

it('covers advance-to-validation decision sanitizers and guard branches', function (): void {
    $action = new AdvancePageImportToValidationAction;

    $state = $action->handle(new PageImportDecisionData(
        sessionId: null,
        reviewRows: [],
        pageDecisions: [],
        resolveRows: [],
        relationDecisions: [],
        canUpdateSharedRelations: false,
    ));

    expect($state->step)->toBe(ImportPagesPage::STEP_UPLOAD);

    $resolveState = $action->handle(new PageImportDecisionData(
        sessionId: 999999,
        reviewRows: [],
        pageDecisions: [],
        resolveRows: [
            [
                'ref' => 'site:1',
                'top_match' => ['local_id' => 1],
                'alternatives' => [['local_id' => 2]],
            ],
        ],
        relationDecisions: [
            'site:1' => [
                'action' => RelationResolveRow::ACTION_CREATE_NEW,
            ],
        ],
        canUpdateSharedRelations: false,
    ));

    expect($resolveState->step)->toBe(ImportPagesPage::STEP_RESOLVE);

    $missingSessionState = $action->handle(new PageImportDecisionData(
        sessionId: 999999,
        reviewRows: [],
        pageDecisions: [],
        resolveRows: [],
        relationDecisions: [],
        canUpdateSharedRelations: false,
    ), true);

    expect($missingSessionState->step)->toBe(ImportPagesPage::STEP_UPLOAD);

    Storage::fake('local');
    migrationAssistantActingAsImportPagesUser();
    $initial = startResidualImport(['pages/page-one.json' => residualImportPage('page-one', '/one')]);
    $session = ImportSession::query()->findOrFail($initial->sessionId);
    $session->forceFill(['resolution_map' => [
        'resolved' => [
            'site:1' => [
                'local_id' => '44',
                'strategy' => 'slug',
                'confidence' => '0.75',
                'reason' => 'Matched slug',
                'alternatives' => [
                    ['local_id' => 45, 'strategy' => 'name'],
                    'invalid',
                ],
            ],
            12 => ['local_id' => 12],
            'site:bad' => 'invalid',
        ],
        'unresolved' => ['site:9', 12, 'site:10'],
    ]])->save();

    $validated = $action->handle(PageImportDecisionData::from([
        'sessionId' => $initial->sessionId,
        'reviewRows' => $initial->reviewRows,
        'pageDecisions' => [
            'page-one' => ['action' => PageReviewRow::ACTION_UPDATE, 'notes' => 'Keep copy'],
            'page-two' => ['action' => 12, 'notes' => ''],
            4 => ['action' => PageReviewRow::ACTION_SKIP],
            'page-three' => 'bad',
        ],
        'resolveRows' => [],
        'relationDecisions' => [
            'site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => '44', 'notes' => 'Matched'],
            'site:2' => ['target_id' => ''],
            8 => ['action' => RelationResolveRow::ACTION_SKIP],
            'site:3' => false,
        ],
        'canUpdateSharedRelations' => false,
    ]), true);
    $relationsSummary = $validated->validationSummary['relations'] ?? null;
    throw_unless(is_array($relationsSummary), RuntimeException::class);
    expect($validated->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($validated->pageDecisions)->toBe([
            'page-one' => ['action' => PageReviewRow::ACTION_UPDATE, 'notes' => 'Keep copy'],
            'page-two' => ['action' => PageReviewRow::ACTION_CREATE],
        ])
        ->and($validated->relationDecisions)->toBe([
            'site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => '44', 'notes' => 'Matched'],
            'site:2' => ['action' => RelationResolveRow::ACTION_USE_EXISTING],
        ])
        ->and($session->refresh()->page_decisions)->toBe($validated->pageDecisions)
        ->and($session->relation_decisions)->toBe($validated->relationDecisions)
        ->and($relationsSummary['match'])->toBe(1)
        ->and($validated->validationSummary['blocking_errors'])->toHaveCount(2);
});

it('derives import target names and default decisions from secured upload state', function (): void {
    Storage::fake('local');
    migrationAssistantActingAsImportPagesUser();
    $localLayoutId = Layout::factory()->create(['name' => 'Known import layout'])->getKey();
    throw_unless(is_int($localLayoutId), RuntimeException::class);
    $detector = new class implements PageCollisionDetector
    {
        #[Override]
        public function detect(array $urls, ?int $resolvedSiteId): array
        {
            return ($urls[0]['url'] ?? '') === '/update'
                ? [PageReviewRow::COLLISION_URL_LIVE, [], PageReviewRow::ACTION_UPDATE]
                : [PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE];
        }
    };
    app()->instance(BuildPageReviewRowsAction::class, new BuildPageReviewRowsAction($detector));
    resolve(RelationMatchResolverRegistry::class)->set('layouts', new readonly class($localLayoutId) implements MatchResolver
    {
        public function __construct(private int $localId) {}

        #[Override]
        public function resolve(array $descriptor, ?int $siteId = null): ?MatchResolution
        {
            return ($descriptor['ref'] ?? null) === 'layout:matched'
                ? new MatchResolution(localId: $this->localId, strategy: 'key', confidence: 1.0, reason: 'Known layout key')
                : null;
        }
    });
    $payload = [
        'pages/page-home.json' => residualImportPage('page-home', '/home'),
        'pages/page-about.json' => residualImportPage('page-about', '/about'),
        'pages/page-update.json' => residualImportPage('page-update', '/update'),
        'relations/layouts/matched.json' => json_encode(['ref' => 'layout:matched', 'name' => 'Known import layout'], JSON_THROW_ON_ERROR),
        'relations/layouts/unmatched.json' => json_encode(['ref' => 'layout:missing', 'name' => 'Unmatched import layout'], JSON_THROW_ON_ERROR),
    ];
    $named = startResidualImport($payload, 'Imported Workspace', true);
    $defaulted = startResidualImport($payload, '');

    expect(fn (): PageImportWizardStateData => (new StartPageImportAction)->handle([]))->toThrow(RuntimeException::class, StartPageImportAction::ERROR_UPLOAD_REQUIRED);
    $defaultName = (string) __('capell-admin::exchanger.import_workspace_default_name');
    throw_if($defaultName === '', RuntimeException::class);
    expect(ImportSession::query()->findOrFail($named->sessionId)->target_label)->toBe('Imported Workspace')
        ->and(ImportSession::query()->findOrFail($defaulted->sessionId)->target_label)->toStartWith($defaultName)
        ->and($named->pageDecisions)->toBe([
            'page-home' => ['action' => PageReviewRow::ACTION_CREATE],
            'page-about' => ['action' => PageReviewRow::ACTION_CREATE],
            'page-update' => ['action' => PageReviewRow::ACTION_UPDATE],
        ])
        ->and($named->relationDecisions['layout:missing'])->toBe(['action' => RelationResolveRow::ACTION_CREATE_NEW, 'target_id' => null])
        ->and($named->relationDecisions['layout:matched'])->toBe(['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => $localLayoutId])
        ->and($defaulted->pageDecisions)->toBe($named->pageDecisions)
        ->and($defaulted->relationDecisions)->toBe($named->relationDecisions)
        ->and(data_get(ImportSession::query()->findOrFail($named->sessionId)->resolution_map, 'resolved.layout:matched.local_id'))->toBe($localLayoutId);
});

it('summarizes validation buckets blockers warnings and media reuse branches', function (): void {
    $reviewRows = [
        new PageReviewRow('page-create', 'Create Page', '/create', 1, 'site:1', [], PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-live', 'Live Collision', '/live', 1, 'site:1', [], PageReviewRow::COLLISION_URL_LIVE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-workspace', null, '/workspace', 1, 'site:1', [], PageReviewRow::COLLISION_URL_WORKSPACE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-skip', 'Skip Page', '/skip', 1, 'site:1', [], PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_SKIP),
    ];

    $pageDecisions = [
        'page-create' => ['action' => PageReviewRow::ACTION_UPDATE],
        'page-live' => ['action' => PageReviewRow::ACTION_CREATE],
        'page-workspace' => ['action' => PageReviewRow::ACTION_CREATE],
        'page-skip' => ['action' => PageReviewRow::ACTION_SKIP],
    ];

    $map = new ResolutionMap(
        resolved: [
            'site:1' => new MatchResolution(
                localId: 1,
                strategy: 'slug',
                alternatives: [
                    new MatchResolution(localId: 2, strategy: 'name', confidence: 0.49),
                ],
            ),
            'layout:7' => new MatchResolution(localId: 7, strategy: 'fingerprint'),
            'type:9' => new MatchResolution(localId: 9, strategy: 'key'),
            'media:3' => new MatchResolution(localId: 3, strategy: 'checksum'),
            'media:4' => new MatchResolution(localId: 4, strategy: 'checksum'),
        ],
        unresolved: ['layout:missing', 'type:missing', 'media:missing', 'site:missing'],
    );

    $relationDecisions = [
        'site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1],
        'layout:7' => ['action' => RelationResolveRow::ACTION_CREATE_NEW],
        'type:9' => ['action' => RelationResolveRow::ACTION_CLONE_IMPORTED],
        'media:3' => ['action' => RelationResolveRow::ACTION_UPDATE_EXISTING],
        'media:4' => ['action' => RelationResolveRow::ACTION_SKIP],
        'layout:missing' => ['action' => RelationResolveRow::ACTION_CREATE_NEW],
        'type:missing' => ['action' => RelationResolveRow::ACTION_CLONE_IMPORTED],
        'media:missing' => ['action' => RelationResolveRow::ACTION_SKIP],
    ];

    $package = new PackageReadResult(
        archivePath: '/tmp/import.zip',
        manifest: [],
        integrity: [],
        payload: [
            'relations/media/reused.json' => json_encode(['ref' => 'media:3'], JSON_THROW_ON_ERROR),
            'relations/media/imported.json' => json_encode(['ref' => 'media:new'], JSON_THROW_ON_ERROR),
            'relations/media/no-ref.json' => json_encode(['name' => 'No ref'], JSON_THROW_ON_ERROR),
            'relations/media/broken.json' => '{',
            'relations/layouts/7.json' => json_encode(['ref' => 'layout:7'], JSON_THROW_ON_ERROR),
            'relations/media/raw.bin' => 'binary',
        ],
    );

    $detector = new class implements PageCollisionDetector
    {
        #[Override]
        public function detect(array $urls, ?int $resolvedSiteId): array
        {
            return match ($urls[0]['url'] ?? '') {
                '/live' => [PageReviewRow::COLLISION_URL_LIVE, [], PageReviewRow::ACTION_CREATE],
                '/workspace' => [PageReviewRow::COLLISION_URL_WORKSPACE, [], PageReviewRow::ACTION_CREATE],
                '/skip' => [PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_SKIP],
                default => [PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE],
            };
        }
    };
    $pages = [];
    foreach ($reviewRows as $row) {
        $pages['pages/' . $row->uuid . '.json'] = json_encode([
            'type' => 'page', 'uuid' => $row->uuid, 'attributes' => ['title' => $row->title],
            'owned_relations' => ['page_urls' => [['url' => $row->primaryUrl, 'site_id' => 1, 'language_id' => 1]]],
            'shared_relations' => ['site' => ['ref' => 'site:1']],
        ], JSON_THROW_ON_ERROR);
    }

    $package = new PackageReadResult($package->archivePath, $package->manifest, $package->integrity, [...$package->payload, ...$pages]);
    $summary = new BuildImportValidationSummaryAction(new BuildPageReviewRowsAction($detector))->run($package, $map, $pageDecisions, $relationDecisions);
    $pageBuckets = $summary->pages;
    $relationBuckets = $summary->relations;
    $pageBlockers = array_slice($summary->blockingErrors, 0, 1);
    $relationBlockers = array_slice($summary->blockingErrors, 1);
    $pageWarnings = array_slice($summary->warnings, 0, 1);
    $relationWarnings = array_slice($summary->warnings, 1);

    expect($pageBuckets)->toBe(['create' => 2, 'update' => 1, 'skip' => 1])
        ->and($pageBlockers)->toBe([
            'Page page-workspace cannot be created — its URL is already claimed by another workspace.',
        ])
        ->and($pageWarnings)->toBe([
            'Page Live Collision is set to "create" but its URL already exists on a live page.',
        ]);

    expect($relationBuckets)->toBe(['match' => 1, 'create' => 2, 'clone' => 2, 'update' => 1, 'skip' => 2])
        ->and($relationBlockers)->toBe([
            'Relation media:3 is set to "update existing" but has no target id.',
            'Relation site:missing is unresolved — pick create/clone/skip before dispatching.',
        ])
        ->and($relationWarnings)->toBe([
            'Relation site:1 has a low-confidence alternative (#2 at 49%).',
        ]);

    expect($summary->media)->toBe([
        'import' => 2,
        'reuse' => 1,
    ]);
});

it('passes page decisions to public workflow actions and sends their notices', function (): void {
    $page = new ImportPagesPage;
    expect($page->canUpdateSharedRelations())->toBeFalse()->and($page->canPublishLive())->toBeFalse();
    $page->sessionId = 5;
    $page->reviewRows = [['uuid' => 'page-home']];
    $page->pageDecisions = ['page-home' => ['action' => PageReviewRow::ACTION_SKIP]];
    $page->resolveRows = [['ref' => 'site:1']];
    $page->relationDecisions = ['site:1' => ['action' => RelationResolveRow::ACTION_CREATE_NEW]];

    foreach ([
        PageImportWizardStateData::NOTICE_UNRESOLVED_REFERENCES => 'unresolved_references',
        PageImportWizardStateData::NOTICE_BLOCKED_BY_WORKSPACE_CONFLICT => 'blocked_by_workspace_conflict',
        PageImportWizardStateData::NOTICE_BLOCKED_PENDING_DECISIONS => 'blocked_pending_decisions',
    ] as $notice => $translation) {
        session()->forget('filament.notifications');
        fakeResidualImportAction(new AdvancePageImportToValidationAction, new PageImportWizardStateData(step: ImportPagesPage::STEP_RESOLVE, sessionId: 5, notice: $notice, noticeCount: 3), function (PageImportDecisionData $decision, bool $force): void {
            expect($decision->sessionId)->toBe(5)
                ->and($decision->canUpdateSharedRelations)->toBeFalse()
                ->and($force)->toBeTrue();
        });
        $page->advanceToValidate();
        Notification::assertNotified(__('capell-admin::exchanger.' . $translation));
        expect($page->step)->toBe(ImportPagesPage::STEP_RESOLVE);
    }

    foreach ([
        PageImportStatusData::NOTICE_SUMMARY_BLOCKING_ERRORS => 'summary_blocking_errors',
        PageImportStatusData::NOTICE_CONFIRMATION_MISMATCH => 'confirmation_mismatch',
        PageImportStatusData::NOTICE_IMPORT_QUEUED => 'import_queued',
    ] as $notice => $translation) {
        session()->forget('filament.notifications');
        $page->step = ImportPagesPage::STEP_VALIDATE;
        fakeResidualImportAction(new DispatchPageImportAction, new PageImportStatusData(step: ImportPagesPage::STEP_VALIDATE, notice: $notice, noticeBody: 'Blocking error'));
        $page->dispatchImport();
        Notification::assertNotified(__('capell-admin::exchanger.' . $translation));
    }

    Storage::fake('local');
    Queue::fake();
    migrationAssistantActingAsImportPagesUser();
    foreach ([' imported workspace ' => true, 'wrong' => false] as $confirmation => $matches) {
        $state = startResidualImport(['pages/confirmation.json' => residualImportPage('confirmation', '/confirmation')], 'Imported Workspace');
        $session = ImportSession::query()->findOrFail($state->sessionId);
        $session->forceFill(['status' => ImportSessionStatus::Validated, 'validation_results' => ['blocking_errors' => []]])->save();
        DispatchPageImportAction::clearFake();
        $status = (new DispatchPageImportAction)->handle($state->sessionId, [], $confirmation, 'Imported Workspace');
        expect($status->notice)->toBe($matches ? PageImportStatusData::NOTICE_IMPORT_QUEUED : PageImportStatusData::NOTICE_CONFIRMATION_MISMATCH);
    }
});

function fakeResidualImportAction(object $action, PageImportWizardStateData|PageImportStatusData $state, ?Closure $assertArguments = null): void
{
    $mock = Mockery::mock($action);
    $mock->shouldReceive('handle')->once()->andReturnUsing(function (mixed ...$arguments) use ($assertArguments, $state): PageImportWizardStateData|PageImportStatusData {
        if ($assertArguments instanceof Closure) {
            $assertArguments(...$arguments);
        }

        return $state;
    });
    app()->instance('LaravelActions:AsFake:' . $action::class, $mock);

}

/** @param array<string, string> $payload */
function startResidualImport(array $payload, string $name = 'Residual import', bool $arrayToken = false): PageImportWizardStateData
{
    $manifest = json_encode(['schema_version' => 1, 'package_type' => 'page-export'], JSON_THROW_ON_ERROR);
    $files = ['manifest.json' => $manifest, ...$payload];
    $integrity = [];
    foreach ($files as $path => $contents) {
        $integrity[$path] = ChecksumGenerator::forString($contents);
    }

    $path = BindMigrationArchiveUploadAction::STAGING_DIRECTORY . '/' . Str::uuid() . '.zip';
    Storage::disk('local')->makeDirectory(dirname($path));
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $filename => $contents) {
        $zip->addFromString($filename, $contents);
    }

    $zip->addFromString('integrity.json', json_encode(['files' => $integrity], JSON_THROW_ON_ERROR));
    $zip->close();

    $state = BindMigrationArchiveUploadAction::run(['archive' => $path, 'workspace_name' => $name]);
    if ($arrayToken) {
        $state['archive'] = ['first' => $state['archive']];
    }

    return (new StartPageImportAction)->handle($state);
}

function residualImportPage(string $uuid, string $url): string
{
    return json_encode(['type' => 'page', 'uuid' => $uuid, 'attributes' => ['title' => $uuid], 'owned_relations' => ['page_urls' => [['url' => $url, 'language_id' => 1]]]], JSON_THROW_ON_ERROR);
}
