<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\BuildImportValidationSummaryAction;
use Capell\MigrationAssistant\Actions\Imports\AdvancePageImportToValidationAction;
use Capell\MigrationAssistant\Actions\Imports\DispatchPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\StartPageImportAction;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\Imports\PageImportStatusData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;

function migrationAssistantReflectionMethod(string $className, string $methodName): ReflectionMethod
{
    return new ReflectionMethod($className, $methodName);
}

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

    $applyWizardState = new ReflectionMethod(ImportPagesPage::class, 'applyWizardState');
    $applyWizardState->invoke($page, new PageImportWizardStateData(
        step: ImportPagesPage::STEP_VALIDATE,
        sessionId: 123,
        reviewRows: [['uuid' => 'page-home']],
        pageDecisions: ['page-home' => ['action' => PageReviewRow::ACTION_CREATE]],
        resolveRows: [['ref' => 'site:1']],
        relationDecisions: ['site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1]],
        validationSummary: ['blocking_errors' => []],
        confirmationExpected: 'Imported Workspace',
    ));

    expect($page->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($page->sessionId)->toBe(123)
        ->and($page->reviewRows)->toBe([['uuid' => 'page-home']])
        ->and($page->pageDecisions)->toBe(['page-home' => ['action' => PageReviewRow::ACTION_CREATE]])
        ->and($page->resolveRows)->toBe([['ref' => 'site:1']])
        ->and($page->relationDecisions)->toBe(['site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1]])
        ->and($page->validationSummary)->toBe(['blocking_errors' => []])
        ->and($page->confirmationExpected)->toBe('Imported Workspace')
        ->and($page->confirmation)->toBe('');

    $applyStatus = new ReflectionMethod(ImportPagesPage::class, 'applyStatus');
    $applyStatus->invoke($page, new PageImportStatusData(
        step: ImportPagesPage::STEP_COMPLETED,
        sessionStatus: ImportSessionStatus::Completed->value,
        resultSummary: ['pages_created' => 2],
        failureReason: null,
        targetId: 88,
        targetUrl: 'https://example.test/imported',
    ));

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

    $sanitizedPageDecisions = new ReflectionMethod(AdvancePageImportToValidationAction::class, 'sanitizedPageDecisions');

    $sanitizedRelations = new ReflectionMethod(AdvancePageImportToValidationAction::class, 'sanitizedRelationDecisions');

    $hydrateResolutionMap = new ReflectionMethod(AdvancePageImportToValidationAction::class, 'hydrateResolutionMap');

    expect($sanitizedPageDecisions->invoke($action, [
        'page-one' => ['action' => PageReviewRow::ACTION_UPDATE, 'notes' => 'Keep copy'],
        'page-two' => ['action' => 12, 'notes' => ''],
        4 => ['action' => PageReviewRow::ACTION_SKIP],
        'page-three' => 'bad',
    ]))->toBe([
        'page-one' => ['action' => PageReviewRow::ACTION_UPDATE, 'notes' => 'Keep copy'],
        'page-two' => ['action' => PageReviewRow::ACTION_CREATE],
    ])
        ->and($sanitizedRelations->invoke($action, [
            'site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => '44', 'notes' => 'Matched'],
            'site:2' => ['target_id' => ''],
            8 => ['action' => RelationResolveRow::ACTION_SKIP],
            'site:3' => false,
        ]))->toBe([
            'site:1' => [
                'action' => RelationResolveRow::ACTION_USE_EXISTING,
                'target_id' => '44',
                'notes' => 'Matched',
            ],
            'site:2' => ['action' => RelationResolveRow::ACTION_USE_EXISTING],
        ]);

    $map = $hydrateResolutionMap->invoke($action, [
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
    ]);

    expect($map->resolved)->toHaveKey('site:1')
        ->and($map->resolved['site:1']->localId)->toBe('44')
        ->and($map->resolved['site:1']->alternatives)->toHaveCount(1)
        ->and($map->unresolved)->toBe(['site:9', 'site:10']);
});

it('derives start page import helper values from upload state and review rows', function (): void {
    $action = new StartPageImportAction;

    $archiveDiskPathFrom = migrationAssistantReflectionMethod(StartPageImportAction::class, 'archiveDiskPathFrom');
    $sourceFilenameFrom = migrationAssistantReflectionMethod(StartPageImportAction::class, 'sourceFilenameFrom');
    $workspaceNameFrom = migrationAssistantReflectionMethod(StartPageImportAction::class, 'workspaceNameFrom');
    $pageDecisionsFromReviewRows = migrationAssistantReflectionMethod(StartPageImportAction::class, 'pageDecisionsFromReviewRows');
    $relationDecisionsFromResolveRows = migrationAssistantReflectionMethod(StartPageImportAction::class, 'relationDecisionsFromResolveRows');

    $reviewRows = [
        new PageReviewRow(
            uuid: 'page-home',
            title: 'Home',
            primaryUrl: '/home',
            resolvedSiteId: 12,
            siteRef: 'site:12',
            urls: [['site_id' => 12, 'language_id' => 1, 'url' => '/home']],
            collisionState: PageReviewRow::COLLISION_NONE,
            conflictMessages: [],
            suggestedAction: PageReviewRow::ACTION_CREATE,
        ),
        new PageReviewRow(
            uuid: 'page-about',
            title: 'About',
            primaryUrl: '/about',
            resolvedSiteId: null,
            siteRef: null,
            urls: [],
            collisionState: PageReviewRow::COLLISION_URL_LIVE,
            conflictMessages: ['Live URL already exists.'],
            suggestedAction: PageReviewRow::ACTION_UPDATE,
        ),
    ];
    $resolveRows = [
        new RelationResolveRow(
            group: RelationResolveRow::GROUP_SITES,
            ref: 'site:12',
            topMatch: ['local_id' => 12, 'strategy' => 'slug', 'confidence' => 0.98, 'reason' => 'Matched slug'],
            alternatives: [],
            warnings: [],
            suggestedAction: RelationResolveRow::ACTION_USE_EXISTING,
        ),
        new RelationResolveRow(
            group: RelationResolveRow::GROUP_LAYOUTS,
            ref: 'layout:7',
            topMatch: null,
            alternatives: [],
            warnings: ['No local match.'],
            suggestedAction: RelationResolveRow::ACTION_CREATE_NEW,
        ),
    ];

    expect($archiveDiskPathFrom->invoke($action, ['archive' => ['first' => 'exchanger/imports/pages.zip']]))
        ->toBe('exchanger/imports/pages.zip')
        ->and($archiveDiskPathFrom->invoke($action, ['archive' => 'exchanger/imports/direct.zip']))
        ->toBe('exchanger/imports/direct.zip')
        ->and($archiveDiskPathFrom->invoke($action, []))
        ->toBe('')
        ->and($sourceFilenameFrom->invoke($action, ['archive_filename' => ['first' => 'pages.zip']]))
        ->toBe('pages.zip')
        ->and($sourceFilenameFrom->invoke($action, ['archive_filename' => 'direct.zip']))
        ->toBe('direct.zip')
        ->and($sourceFilenameFrom->invoke($action, ['archive_filename' => '']))
        ->toBeNull()
        ->and($workspaceNameFrom->invoke($action, ['workspace_name' => 'Imported Workspace']))
        ->toBe('Imported Workspace')
        ->and($workspaceNameFrom->invoke($action, ['workspace_name' => '']))
        ->toStartWith((string) __('capell-admin::exchanger.import_workspace_default_name'))
        ->and($pageDecisionsFromReviewRows->invoke($action, $reviewRows))
        ->toBe([
            'page-home' => ['action' => PageReviewRow::ACTION_CREATE],
            'page-about' => ['action' => PageReviewRow::ACTION_UPDATE],
        ])
        ->and($relationDecisionsFromResolveRows->invoke($action, $resolveRows))
        ->toBe([
            'site:12' => [
                'action' => RelationResolveRow::ACTION_USE_EXISTING,
                'target_id' => 12,
            ],
            'layout:7' => [
                'action' => RelationResolveRow::ACTION_CREATE_NEW,
                'target_id' => null,
            ],
        ]);
});

it('summarizes validation buckets blockers warnings and media reuse branches', function (): void {
    $action = new BuildImportValidationSummaryAction;

    $summarizePages = migrationAssistantReflectionMethod(BuildImportValidationSummaryAction::class, 'summarizePages');
    $summarizeRelations = migrationAssistantReflectionMethod(BuildImportValidationSummaryAction::class, 'summarizeRelations');
    $summarizeMedia = migrationAssistantReflectionMethod(BuildImportValidationSummaryAction::class, 'summarizeMedia');

    $reviewRows = [
        new PageReviewRow('page-create', 'Create Page', '/create', 1, 'site:1', [], PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-live', 'Live Collision', '/live', 1, 'site:1', [], PageReviewRow::COLLISION_URL_LIVE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-workspace', null, '/workspace', 1, 'site:1', [], PageReviewRow::COLLISION_URL_WORKSPACE, [], PageReviewRow::ACTION_CREATE),
        new PageReviewRow('page-skip', 'Skip Page', '/skip', 1, 'site:1', [], PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_SKIP),
    ];

    [$pageBuckets, $pageBlockers, $pageWarnings] = $summarizePages->invoke($action, $reviewRows, [
        'page-create' => ['action' => PageReviewRow::ACTION_UPDATE],
        'page-live' => ['action' => PageReviewRow::ACTION_CREATE],
        'page-workspace' => ['action' => PageReviewRow::ACTION_CREATE],
        'page-skip' => ['action' => PageReviewRow::ACTION_SKIP],
    ]);

    expect($pageBuckets)->toBe(['create' => 2, 'update' => 1, 'skip' => 1])
        ->and($pageBlockers)->toBe([
            'Page page-workspace cannot be created — its URL is already claimed by another workspace.',
        ])
        ->and($pageWarnings)->toBe([
            'Page Live Collision is set to "create" but its URL already exists on a live page.',
        ]);

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

    [$relationBuckets, $relationBlockers, $relationWarnings] = $summarizeRelations->invoke($action, $map, [
        'site:1' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 1],
        'layout:7' => ['action' => RelationResolveRow::ACTION_CREATE_NEW],
        'type:9' => ['action' => RelationResolveRow::ACTION_CLONE_IMPORTED],
        'media:3' => ['action' => RelationResolveRow::ACTION_UPDATE_EXISTING],
        'media:4' => ['action' => RelationResolveRow::ACTION_SKIP],
        'layout:missing' => ['action' => RelationResolveRow::ACTION_CREATE_NEW],
        'type:missing' => ['action' => RelationResolveRow::ACTION_CLONE_IMPORTED],
        'media:missing' => ['action' => RelationResolveRow::ACTION_SKIP],
    ]);

    expect($relationBuckets)->toBe(['match' => 1, 'create' => 2, 'clone' => 2, 'update' => 1, 'skip' => 2])
        ->and($relationBlockers)->toBe([
            'Relation media:3 is set to "update existing" but has no target id.',
            'Relation site:missing is unresolved — pick create/clone/skip before dispatching.',
        ])
        ->and($relationWarnings)->toBe([
            'Relation site:1 has a low-confidence alternative (#2 at 49%).',
        ]);

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

    expect($summarizeMedia->invoke($action, $package, $map))->toBe([
        'import' => 2,
        'reuse' => 1,
    ]);
});

it('covers import page permission guards notice dispatchers and status matching helpers', function (): void {
    $page = new ImportPagesPage;

    expect($page->canUpdateSharedRelations())->toBeFalse()
        ->and($page->canPublishLive())->toBeFalse();

    $decisionData = migrationAssistantReflectionMethod(ImportPagesPage::class, 'decisionData');
    $sendWizardNotice = migrationAssistantReflectionMethod(ImportPagesPage::class, 'sendWizardNotice');
    $sendStatusNotice = migrationAssistantReflectionMethod(ImportPagesPage::class, 'sendStatusNotice');

    $page->sessionId = 5;
    $page->reviewRows = [['uuid' => 'page-home']];
    $page->pageDecisions = ['page-home' => ['action' => PageReviewRow::ACTION_SKIP]];
    $page->resolveRows = [['ref' => 'site:1']];
    $page->relationDecisions = ['site:1' => ['action' => RelationResolveRow::ACTION_CREATE_NEW]];

    $data = $decisionData->invoke($page);

    expect($data)->toBeInstanceOf(PageImportDecisionData::class)
        ->and($data->sessionId)->toBe(5)
        ->and($data->canUpdateSharedRelations)->toBeFalse();

    foreach ([
        PageImportWizardStateData::NOTICE_UNRESOLVED_REFERENCES,
        PageImportWizardStateData::NOTICE_BLOCKED_BY_WORKSPACE_CONFLICT,
        PageImportWizardStateData::NOTICE_BLOCKED_PENDING_DECISIONS,
        null,
    ] as $notice) {
        $sendWizardNotice->invoke($page, new PageImportWizardStateData(
            step: ImportPagesPage::STEP_RESOLVE,
            notice: $notice,
            noticeCount: 3,
        ));
    }

    foreach ([
        PageImportStatusData::NOTICE_SUMMARY_BLOCKING_ERRORS,
        PageImportStatusData::NOTICE_CONFIRMATION_MISMATCH,
        PageImportStatusData::NOTICE_IMPORT_QUEUED,
        null,
    ] as $notice) {
        $sendStatusNotice->invoke($page, new PageImportStatusData(
            step: ImportPagesPage::STEP_VALIDATE,
            notice: $notice,
            noticeBody: 'Blocking error',
        ));
    }

    $confirmationMatches = migrationAssistantReflectionMethod(DispatchPageImportAction::class, 'confirmationMatches');
    $dispatch = new DispatchPageImportAction;

    expect($confirmationMatches->invoke($dispatch, '', ''))->toBeTrue()
        ->and($confirmationMatches->invoke($dispatch, ' imported workspace ', 'Imported Workspace'))->toBeTrue()
        ->and($confirmationMatches->invoke($dispatch, 'wrong', 'Imported Workspace'))->toBeFalse();
});
