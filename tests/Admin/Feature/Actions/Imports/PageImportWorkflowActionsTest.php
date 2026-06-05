<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\Imports\AdvancePageImportToValidationAction;
use Capell\MigrationAssistant\Actions\Imports\DispatchPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\RefreshPageImportStatusAction;
use Capell\MigrationAssistant\Actions\Imports\ResolvePageImportSessionAction;
use Capell\MigrationAssistant\Actions\Imports\StartPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\StartSiteImportAction;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ChecksumGenerator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(CreatesAdminUser::class)
    ->group('page-import-actions');

function writeActionImportPackage(
    string $absolutePath,
    string $pageUuid,
    int $siteId,
    string $url,
    ?int $layoutId = null,
): void {
    $manifestJson = json_encode([
        'schema_version' => 1,
        'package_type' => 'page-export',
    ], JSON_THROW_ON_ERROR);

    $sharedRelations = [
        'site' => ['ref' => 'site:' . $siteId],
    ];

    if ($layoutId !== null) {
        $sharedRelations['layout'] = ['ref' => 'layout:' . $layoutId];
    }

    $pageJson = json_encode([
        'type' => 'page',
        'uuid' => $pageUuid,
        'id' => 123,
        'attributes' => ['title' => 'Action Imported Page'],
        'owned_relations' => [
            'page_urls' => [
                ['site_id' => $siteId, 'language_id' => 1, 'url' => $url],
            ],
        ],
        'shared_relations' => $sharedRelations,
    ], JSON_THROW_ON_ERROR);

    $integrityFiles = [
        'manifest.json' => ChecksumGenerator::forString($manifestJson),
        sprintf('pages/%s.json', $pageUuid) => ChecksumGenerator::forString($pageJson),
    ];

    if ($layoutId !== null) {
        $layoutDescriptorJson = json_encode([
            'ref' => 'layout:' . $layoutId,
            'fingerprint' => 'layout-' . $layoutId,
            'name' => 'Action Layout',
        ], JSON_THROW_ON_ERROR);

        $integrityFiles[sprintf('relations/layouts/%d.json', $layoutId)] = ChecksumGenerator::forString($layoutDescriptorJson);
    }

    $zipArchive = new ZipArchive;
    $zipArchive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zipArchive->addFromString('manifest.json', $manifestJson);
    $zipArchive->addFromString('integrity.json', json_encode(['files' => $integrityFiles], JSON_THROW_ON_ERROR));
    $zipArchive->addFromString(sprintf('pages/%s.json', $pageUuid), $pageJson);

    if (isset($layoutDescriptorJson)) {
        $zipArchive->addFromString(sprintf('relations/layouts/%d.json', $layoutId), $layoutDescriptorJson);
    }

    $zipArchive->close();
}

function stageActionImportPackage(
    string $relativePath,
    string $pageUuid,
    int $siteId,
    string $url,
    ?int $layoutId = null,
): void {
    $absolutePath = Storage::disk('local')->path($relativePath);
    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    writeActionImportPackage($absolutePath, $pageUuid, $siteId, $url, $layoutId);
}

function stageActionSiteImportPackage(string $relativePath, string $pageUuid, int $sourceSiteId): void
{
    $absolutePath = Storage::disk('local')->path($relativePath);
    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    $manifestJson = json_encode([
        'schema_version' => 1,
        'package_type' => 'site-export',
    ], JSON_THROW_ON_ERROR);

    $siteDescriptorJson = json_encode([
        'type' => 'site',
        'ref' => 'site:' . $sourceSiteId,
        'id' => $sourceSiteId,
        'attributes' => [
            'name' => 'Action Imported Site',
            'status' => true,
            'default' => false,
        ],
    ], JSON_THROW_ON_ERROR);

    $pageJson = json_encode([
        'type' => 'page',
        'uuid' => $pageUuid,
        'id' => 456,
        'attributes' => [
            'title' => 'Action Imported Site Page',
            'site_id' => $sourceSiteId,
        ],
        'owned_relations' => [
            'page_urls' => [
                ['site_id' => $sourceSiteId, 'language_id' => 1, 'url' => '/site-action'],
            ],
        ],
        'shared_relations' => [
            'site' => ['ref' => 'site:' . $sourceSiteId],
        ],
    ], JSON_THROW_ON_ERROR);

    $integrityFiles = [
        'manifest.json' => ChecksumGenerator::forString($manifestJson),
        sprintf('pages/%s.json', $pageUuid) => ChecksumGenerator::forString($pageJson),
        'relations/sites/source-site.json' => ChecksumGenerator::forString($siteDescriptorJson),
    ];

    $zipArchive = new ZipArchive;
    $zipArchive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zipArchive->addFromString('manifest.json', $manifestJson);
    $zipArchive->addFromString('integrity.json', json_encode(['files' => $integrityFiles], JSON_THROW_ON_ERROR));
    $zipArchive->addFromString(sprintf('pages/%s.json', $pageUuid), $pageJson);
    $zipArchive->addFromString('relations/sites/source-site.json', $siteDescriptorJson);
    $zipArchive->close();
}

/**
 * @return array{PageImportWizardStateData, string, Site}
 */
function startActionImportWizard(string $archiveName, string $workspaceName, ?int $layoutId = null): array
{
    $site = Site::factory()->create(['name' => 'Action Site']);
    $pageUuid = (string) Str::uuid();
    $relativePath = sprintf('exchanger/imports/%s', $archiveName);

    stageActionImportPackage(
        $relativePath,
        $pageUuid,
        (int) $site->getKey(),
        '/action-' . Str::random(8),
        $layoutId,
    );

    $state = StartPageImportAction::run([
        'archive' => $relativePath,
        'archive_filename' => $archiveName,
        'workspace_name' => $workspaceName,
    ]);

    return [$state, $pageUuid, $site];
}

function actionDecisionDataFromState(PageImportWizardStateData $state, bool $canUpdateSharedRelations = true): PageImportDecisionData
{
    return new PageImportDecisionData(
        sessionId: $state->sessionId,
        reviewRows: $state->reviewRows,
        pageDecisions: $state->pageDecisions,
        resolveRows: $state->resolveRows,
        relationDecisions: $state->relationDecisions,
        canUpdateSharedRelations: $canUpdateSharedRelations,
    );
}

function actionImportSessionForState(PageImportWizardStateData $state): ImportSession
{
    $sessionId = $state->sessionId;

    throw_if($sessionId === null, RuntimeException::class, 'Expected import wizard state to have a session id.');

    return ImportSession::query()
        ->whereKey($sessionId)
        ->firstOrFail();
}

beforeEach(function (): void {
    migrationAssistantActingAsImportPagesUser();
    Storage::fake('local');
    Queue::fake();
});

it('moves upload state to review state after parsing a package', function (): void {
    [$state, $pageUuid] = startActionImportWizard('action-review.zip', 'Action Review');

    expect($state->step)->toBe(ImportPagesPage::STEP_REVIEW)
        ->and($state->sessionId)->toBeInt()
        ->and($state->reviewRows[0]['uuid'] ?? null)->toBe($pageUuid)
        ->and($state->pageDecisions[$pageUuid]['action'] ?? null)->toBe(PageReviewRow::ACTION_CREATE);

    $session = actionImportSessionForState($state);
    expect($session->status)->toBe(ImportSessionStatus::Parsed);

    Queue::assertNotPushed(ExecuteImportPlanJob::class);
});

it('moves site upload state to review state using a site import session', function (): void {
    $pageUuid = (string) Str::uuid();
    $sourceSiteId = 654;
    $relativePath = 'exchanger/imports/site-action-review.zip';

    stageActionSiteImportPackage($relativePath, $pageUuid, $sourceSiteId);

    $state = StartSiteImportAction::run([
        'archive' => $relativePath,
        'archive_filename' => 'site-action-review.zip',
        'workspace_name' => 'Site Action Review',
    ]);

    $session = actionImportSessionForState($state);

    expect($state->step)->toBe(ImportPagesPage::STEP_REVIEW)
        ->and($state->sessionId)->toBeInt()
        ->and($state->reviewRows[0]['uuid'] ?? null)->toBe($pageUuid)
        ->and($state->reviewRows[0]['site_ref'] ?? null)->toBe('site:' . $sourceSiteId)
        ->and($state->resolveRows[0]['ref'] ?? null)->toBe('site:' . $sourceSiteId)
        ->and($state->relationDecisions['site:' . $sourceSiteId]['action'] ?? null)->toBe(RelationResolveRow::ACTION_CREATE_NEW)
        ->and($session->kind)->toBe(ImportSessionKind::SiteImport)
        ->and($session->status)->toBe(ImportSessionStatus::Mapped);

    Queue::assertNotPushed(ExecuteImportPlanJob::class);
});

it('rejects page imports when the uploaded package is a site export', function (): void {
    $pageUuid = (string) Str::uuid();
    $relativePath = 'exchanger/imports/site-export-uploaded-as-page.zip';

    stageActionSiteImportPackage($relativePath, $pageUuid, 765);

    expect(fn (): mixed => StartPageImportAction::run([
        'archive' => $relativePath,
        'archive_filename' => 'site-export-uploaded-as-page.zip',
        'workspace_name' => 'Wrong Kind',
    ]))->toThrow(RuntimeException::class, 'Expected a page-export package for page-import; got site-export.');

    expect(ImportSession::query()->count())->toBe(0);
});

it('does not create an import session when the uploaded archive cannot be parsed', function (): void {
    Storage::disk('local')->put('exchanger/imports/broken-import.zip', 'not a zip');

    expect(fn (): mixed => StartPageImportAction::run([
        'archive' => 'exchanger/imports/broken-import.zip',
        'archive_filename' => 'broken-import.zip',
        'workspace_name' => 'Broken Import',
    ]))->toThrow(RuntimeException::class);

    expect(ImportSession::query()->count())->toBe(0);
});

it('resolves only page import sessions owned by the active user', function (): void {
    [$state] = startActionImportWizard('action-owned-session.zip', 'Action Owned Session');

    expect(ResolvePageImportSessionAction::run($state->sessionId))->toBeInstanceOf(ImportSession::class);

    test()->actingAsAdmin(['email' => 'other-import-admin@example.test']);

    expect(ResolvePageImportSessionAction::run($state->sessionId))->toBeNull()
        ->and(AdvancePageImportToValidationAction::run(actionDecisionDataFromState($state), true)->step)
        ->toBe(ImportPagesPage::STEP_UPLOAD)
        ->and(DispatchPageImportAction::run($state->sessionId, [], '', '')->step)
        ->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and(RefreshPageImportStatusAction::run($state->sessionId, null)->step)
        ->toBe(ImportPagesPage::STEP_EXECUTING);
});

it('moves review state to resolve when shared relations need decisions', function (): void {
    [$state] = startActionImportWizard('action-resolve.zip', 'Action Resolve', 777);

    $nextState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    expect($nextState->step)->toBe(ImportPagesPage::STEP_RESOLVE)
        ->and($nextState->resolveRows)->not->toBeEmpty();
});

it('moves resolve state to validate after decisions are sanitized and summarized', function (): void {
    [$state, $pageUuid] = startActionImportWizard('action-validate.zip', 'Action Validate', 888);

    $relationDecisions = $state->relationDecisions;
    $relationDecisions['layout:888'] = [
        'action' => RelationResolveRow::ACTION_CREATE_NEW,
        'notes' => '',
    ];

    $nextState = AdvancePageImportToValidationAction::run(
        new PageImportDecisionData(
            sessionId: $state->sessionId,
            reviewRows: $state->reviewRows,
            pageDecisions: $state->pageDecisions,
            resolveRows: $state->resolveRows,
            relationDecisions: $relationDecisions,
            canUpdateSharedRelations: true,
        ),
        true,
    );

    expect($nextState->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($nextState->validationSummary['pages'] ?? null)->toBeArray()
        ->and($nextState->confirmationExpected)->toBe('Action Validate');

    $session = actionImportSessionForState($state);
    $relationDecisions = $session->relation_decisions ?? [];
    $layoutDecision = $relationDecisions['layout:888'] ?? null;

    throw_unless(is_array($layoutDecision), RuntimeException::class, 'Expected layout relation decision.');

    expect($session->status)->toBe(ImportSessionStatus::Validated)
        ->and($session->page_decisions[$pageUuid]['action'] ?? null)->toBe(PageReviewRow::ACTION_CREATE)
        ->and($layoutDecision['action'] ?? null)->toBe(RelationResolveRow::ACTION_CREATE_NEW)
        ->and($layoutDecision)->not->toHaveKey('notes');
});

it('moves validate state to executing and queues the import job', function (): void {
    [$state] = startActionImportWizard('action-dispatch.zip', 'Action Dispatch');

    $validatedState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    $status = DispatchPageImportAction::run(
        sessionId: $validatedState->sessionId,
        validationSummary: $validatedState->validationSummary,
        confirmation: $validatedState->confirmationExpected,
        confirmationExpected: $validatedState->confirmationExpected,
    );

    expect($status->step)->toBe(ImportPagesPage::STEP_EXECUTING)
        ->and($status->sessionStatus)->toBe(ImportSessionStatus::Queued->value)
        ->and($status->targetId)->toBeNull();

    Queue::assertPushed(ExecuteImportPlanJob::class, 1);
});

it('does not queue duplicate import jobs when dispatch is submitted twice', function (): void {
    [$state] = startActionImportWizard('action-dispatch-once.zip', 'Action Dispatch Once');

    $validatedState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    DispatchPageImportAction::run(
        sessionId: $validatedState->sessionId,
        validationSummary: $validatedState->validationSummary,
        confirmation: $validatedState->confirmationExpected,
        confirmationExpected: $validatedState->confirmationExpected,
    );

    $secondStatus = DispatchPageImportAction::run(
        sessionId: $validatedState->sessionId,
        validationSummary: $validatedState->validationSummary,
        confirmation: $validatedState->confirmationExpected,
        confirmationExpected: $validatedState->confirmationExpected,
    );

    expect($secondStatus->step)->toBe(ImportPagesPage::STEP_EXECUTING)
        ->and($secondStatus->sessionStatus)->toBe(ImportSessionStatus::Queued->value)
        ->and(actionImportSessionForState($validatedState)->status)->toBe(ImportSessionStatus::Queued);

    Queue::assertPushed(ExecuteImportPlanJob::class, 1);
});

it('derives dispatch confirmation from stored session state', function (): void {
    [$state] = startActionImportWizard('action-dispatch-confirmation.zip', 'Action Dispatch Confirmation');

    $validatedState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    $status = DispatchPageImportAction::run(
        sessionId: $validatedState->sessionId,
        validationSummary: $validatedState->validationSummary,
        confirmation: '',
        confirmationExpected: '',
    );

    expect($status->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($status->notice)->toBe($status::NOTICE_CONFIRMATION_MISMATCH);

    Queue::assertNotPushed(ExecuteImportPlanJob::class);
});

it('stores the derived dispatch confirmation target with validation results', function (): void {
    [$state] = startActionImportWizard('action-validation-confirmation.zip', 'Action Validation Confirmation');

    $validatedState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    $session = actionImportSessionForState($validatedState);

    expect($session->validation_results['confirmation_expected'] ?? null)
        ->toBe($validatedState->confirmationExpected);
});

it('uses stored validation blockers when dispatching an import', function (): void {
    [$state] = startActionImportWizard('action-blocked-dispatch.zip', 'Action Blocked Dispatch');

    $validatedState = AdvancePageImportToValidationAction::run(
        actionDecisionDataFromState($state),
        false,
    );

    actionImportSessionForState($validatedState)
        ->forceFill([
            'validation_results' => [
                'blocking_errors' => ['Stored blocker'],
            ],
        ])
        ->save();

    $status = DispatchPageImportAction::run(
        sessionId: $validatedState->sessionId,
        validationSummary: ['blocking_errors' => []],
        confirmation: $validatedState->confirmationExpected,
        confirmationExpected: $validatedState->confirmationExpected,
    );

    expect($status->step)->toBe(ImportPagesPage::STEP_VALIDATE)
        ->and($status->notice)->toBe($status::NOTICE_SUMMARY_BLOCKING_ERRORS)
        ->and($status->noticeBody)->toBe('Stored blocker');

    Queue::assertNotPushed(ExecuteImportPlanJob::class);
});

it('moves executing state to completed when the session completes', function (): void {
    [$state] = startActionImportWizard('action-completed.zip', 'Action Completed');

    $session = actionImportSessionForState($state);
    $session->forceFill([
        'status' => ImportSessionStatus::Completed,
        'result_summary' => [
            'pages_imported' => 4,
            'relations_resolved' => 2,
        ],
    ])->save();

    $status = RefreshPageImportStatusAction::run($state->sessionId, null);

    expect($status->step)->toBe(ImportPagesPage::STEP_COMPLETED)
        ->and($status->sessionStatus)->toBe(ImportSessionStatus::Completed->value)
        ->and($status->resultSummary['pages_imported'] ?? null)->toBe(4);
});

it('moves executing state to failed when the session fails', function (): void {
    [$state] = startActionImportWizard('action-failed.zip', 'Action Failed');

    $session = actionImportSessionForState($state);
    $session->forceFill([
        'status' => ImportSessionStatus::Failed,
        'failure_reason' => 'import execution failed',
    ])->save();

    $status = RefreshPageImportStatusAction::run($state->sessionId, null);

    expect($status->step)->toBe(ImportPagesPage::STEP_FAILED)
        ->and($status->sessionStatus)->toBe(ImportSessionStatus::Failed->value)
        ->and($status->failureReason)->toBe('import execution failed');
});
