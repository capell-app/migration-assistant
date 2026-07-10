<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('deletes an authorized signed import target and writes an audit record', function (): void {
    $actor = migrationAssistantRollbackActor();
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport($page, $actor);

    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    $audit = ImportRollbackAudit::query()->sole();

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(1)
        ->and($result->skipped)->toBe([])
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeFalse()
        ->and($audit->actor_id)->toBe($actor->getKey())
        ->and($audit->outcome)->toBe('completed')
        ->and($audit->deleted)->toBe(1);
});

it('fails closed when rollback provenance is tampered, including in dry runs', function (): void {
    $actor = migrationAssistantRollbackActor();
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport($page, $actor);
    $provenance = $report->provenance ?? [];
    $provenance['entries'][0]['id'] = 999999;

    $report->forceFill(['provenance' => $provenance])->saveQuietly();

    $result = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor, dryRun: true);

    expect($result->matched)->toBe(0)
        ->and($result->deleted)->toBe(0)
        ->and($result->skipped)->toBe([[
            'type' => 'report',
            'id' => $report->getKey(),
            'reason' => 'invalid_provenance',
        ]])
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue()
        ->and(ImportRollbackAudit::query()->sole()->outcome)->toBe('rejected');
});

it('ignores mutable legacy class and id report rows when choosing deletion targets', function (): void {
    $actor = migrationAssistantRollbackActor();
    $page = Page::factory()->create();
    $unrelatedUser = User::factory()->create();
    $report = migrationAssistantRollbackReport($page, $actor);

    $report->forceFill([
        'created_models' => [['class' => User::class, 'id' => $unrelatedUser->getKey()]],
    ])->saveQuietly();

    $result = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor);

    expect($result->deleted)->toBe(1)
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeFalse()
        ->and(User::query()->whereKey($unrelatedUser->getKey())->exists())->toBeTrue();
});

it('does not delete a signed target that has moved to another site', function (): void {
    $actor = migrationAssistantRollbackActor();
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport($page, $actor);
    $otherSite = Site::factory()->create();

    Page::query()->withoutGlobalScopes()->whereKey($page->getKey())->update(['site_id' => $otherSite->getKey()]);

    $result = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor);

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(0)
        ->and($result->skipped)->toBe([[
            'type' => 'page',
            'id' => $page->getKey(),
            'reason' => 'cross_site',
        ]])
        ->and(Page::query()->withoutGlobalScopes()->whereKey($page->getKey())->exists())->toBeTrue();
});

it('treats unchanged equal timestamps as rollback-safe and rejects edited targets', function (): void {
    $actor = migrationAssistantRollbackActor();
    $safePage = Page::factory()->create();
    $safePage->forceFill(['updated_at' => $safePage->created_at])->saveQuietly();
    $safeReport = migrationAssistantRollbackReport($safePage->refresh(), $actor);

    $safeResult = ExecuteImportRollbackAction::run($safeReport, actor: $actor);

    $editedPage = Page::factory()->create();
    $editedReport = migrationAssistantRollbackReport($editedPage, $actor);
    $editedPage->forceFill(['updated_at' => now()->addSecond()])->saveQuietly();

    $editedResult = ExecuteImportRollbackAction::run($editedReport, actor: $actor);

    expect($safeResult->deleted)->toBe(1)
        ->and(Page::query()->whereKey($safePage->getKey())->exists())->toBeFalse()
        ->and($editedResult->deleted)->toBe(0)
        ->and($editedResult->skipped[0]['reason'] ?? null)->toBe('edited_after_import')
        ->and(Page::query()->whereKey($editedPage->getKey())->exists())->toBeTrue();
});

it('requires an actor with access to the target site before deleting signed import targets', function (): void {
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport($page, migrationAssistantRollbackActor());
    $unauthorizedActor = User::factory()->create();

    $result = ExecuteImportRollbackAction::run($report, actor: $unauthorizedActor);

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(0)
        ->and($result->skipped[0]['reason'] ?? null)->toBe('unauthorized')
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue();
});

function migrationAssistantRollbackActor(): User
{
    $actor = test()->actingAsAdmin()->authenticatedUser();

    assert($actor instanceof User);

    return $actor;
}

function migrationAssistantRollbackReport(Page $page, User $actor): ImportRollbackReport
{
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $actor->getKey(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'executed_at' => now(),
    ]);

    return CreateImportRollbackReportAction::run($session, new ImportExecutionReport(
        pagesCreated: 1,
        pagesSkipped: 0,
        createdPageIds: [$page->getKey()],
        errors: [],
    ));
}
