<?php

declare(strict_types=1);

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\MigrationAssistant\Data\ExternalImportPreview;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Data\ExternalPageImportTargetData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ExternalImportPreviewBuilder;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    $this->actingAsAdmin();
});

it('executes external preview rows into pages with an import session and rollback report', function (): void {
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();

    $preview = (new ExternalImportPreviewBuilder)->build(new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title', 'content'],
        rows: [
            [
                'title' => 'External imported page',
                'content' => '<p>Imported body</p>',
            ],
        ],
        suggestedTarget: 'page',
    ));

    $result = ExecuteExternalPageImportAction::run(
        $preview,
        migrationAssistantExternalTarget($site, $layout, $type),
        sourceFilename: 'external.csv',
        targetLabel: 'External import target',
    );

    $page = Page::query()
        ->withoutGlobalScopes()
        ->whereKey($result->report->createdPageIds[0])
        ->firstOrFail();
    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $result->session->getKey())
        ->firstOrFail();

    expect($result->session->status)->toBe(ImportSessionStatus::Completed)
        ->and($result->session->source_environment)->toBe('external')
        ->and($result->session->source_filename)->toBe('external.csv')
        ->and($result->report->pagesCreated)->toBe(1)
        ->and($result->report->errors)->toBe([])
        ->and($page->name)->toBe('External imported page')
        ->and($page->meta['content'] ?? null)->toBe('<p>Imported body</p>')
        ->and(migrationAssistantNumericAttribute($page, 'layout_id'))->toBe(migrationAssistantModelId($layout))
        ->and(migrationAssistantNumericAttribute($page, 'blueprint_id'))->toBe(migrationAssistantModelId($type))
        ->and(migrationAssistantNumericAttribute($page, 'site_id'))->toBe(migrationAssistantModelId($site))
        ->and($rollbackReport->summary['pages_created'] ?? null)->toBe(1)
        ->and($rollbackReport->created_models[0]['id'] ?? null)->toBe($page->getKey());
});

it('rejects external previews without an authenticated actor before writing', function (): void {
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $preview = (new ExternalImportPreviewBuilder)->build(new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title'],
        rows: [['title' => 'Missing defaults']],
        suggestedTarget: 'page',
    ));

    auth()->logout();

    ExecuteExternalPageImportAction::run($preview, migrationAssistantExternalTarget($site, $layout, $type));
})->throws(AuthorizationException::class, 'authenticated actor');

it('rejects external previews before writing when page references are missing', function (): void {
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $preview = (new ExternalImportPreviewBuilder)->build(new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title'],
        rows: [['title' => 'Missing layout']],
        suggestedTarget: 'page',
    ));

    try {
        ExecuteExternalPageImportAction::run($preview, new ExternalPageImportTargetData(
            siteId: (int) $site->getKey(),
            layoutId: 999999,
            blueprintId: (int) $type->getKey(),
            languageId: (int) $site->language_id,
        ));
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('layout')
            ->and(ImportSession::query()->count())->toBe(0);

        return;
    }

    Assert::fail('Expected external execution to reject missing page references.');
});

it('creates rollback reports for failed external executions with created pages', function (): void {
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->page()->create();
    $site = Site::factory()->create();
    $preview = (new ExternalImportPreviewBuilder)->build(new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title'],
        rows: [['title' => 'Partially imported page']],
        suggestedTarget: 'page',
    ));

    $this->app->instance(PageImportService::class, new class
    {
        public function import(PackageReadResult $package, ResolutionMap $resolutionMap, ?int $targetContextId = null): ImportExecutionReport
        {
            $page = Page::factory()->create();

            return new ImportExecutionReport(
                pagesCreated: 1,
                pagesSkipped: 0,
                createdPageIds: [$page->getKey()],
                errors: ['Second row failed.'],
            );
        }
    });

    $result = ExecuteExternalPageImportAction::run($preview, migrationAssistantExternalTarget($site, $layout, $type));

    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $result->session->getKey())
        ->firstOrFail();

    $summary = $rollbackReport->summary;
    throw_unless(is_array($summary), RuntimeException::class, 'Expected rollback report summary.');

    expect($result->session->status)->toBe(ImportSessionStatus::Failed)
        ->and($rollbackReport->created_models)->toBe([
            ['class' => Page::class, 'id' => $result->report->createdPageIds[0]],
        ])
        ->and($summary['errors'])->toBe(['Second row failed.']);
});

it('rejects a target site outside the actor scope and ignores row supplied target ids', function (): void {
    $authorizedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $authorizedLayout = Layout::factory()->site($authorizedSite)->create();
    $otherLayout = Layout::factory()->site($otherSite)->create();
    $authorizedBlueprint = Blueprint::factory()->page()->create();
    $otherBlueprint = Blueprint::factory()->page()->create();
    $actor = $this->actingAsUser()->authenticatedUser();
    $role = Role::findOrCreate('site_editor');
    $configuredRoleAssignmentsTable = config('permission.table_names.model_has_roles', 'model_has_roles');
    $configuredTeamColumn = config('permission.column_names.team_foreign_key', 'team_id');
    $roleAssignmentsTable = is_string($configuredRoleAssignmentsTable) ? $configuredRoleAssignmentsTable : 'model_has_roles';
    $teamColumn = is_string($configuredTeamColumn) ? $configuredTeamColumn : 'team_id';
    DB::table($roleAssignmentsTable)->insert([
        $teamColumn => $authorizedSite->getKey(),
        'role_id' => $role->getKey(),
        'model_type' => $actor->getMorphClass(),
        'model_id' => $actor->getKey(),
    ]);
    $actor->unsetRelation('roles');

    expect($actor->getAssignedSiteIds()->all())->toBe([$authorizedSite->getKey()])
        ->and(SiteScope::isGlobalActor($actor))->toBeFalse()
        ->and(SiteScope::actorCanUseSite($actor, $otherSite))->toBeFalse();

    $preview = new ExternalImportPreview(
        target: 'page',
        creates: 1,
        skips: 0,
        rows: [[
            'row' => 1,
            'action' => 'create',
            'attributes' => [
                'name' => 'Scoped import',
                'site_id' => $otherSite->getKey(),
                'layout_id' => $otherLayout->getKey(),
                'blueprint_id' => $otherBlueprint->getKey(),
            ],
        ]],
    );

    expect(fn (): mixed => ExecuteExternalPageImportAction::run(
        $preview,
        migrationAssistantExternalTarget($otherSite, $otherLayout, $otherBlueprint),
    ))->toThrow(AuthorizationException::class, 'not authorized');

    $result = ExecuteExternalPageImportAction::run(
        $preview,
        migrationAssistantExternalTarget($authorizedSite, $authorizedLayout, $authorizedBlueprint),
    );
    $page = Page::query()->withoutGlobalScopes()->findOrFail($result->report->createdPageIds[0]);

    expect(migrationAssistantNumericAttribute($page, 'site_id'))->toBe(migrationAssistantModelId($authorizedSite))
        ->and(migrationAssistantNumericAttribute($page, 'layout_id'))->toBe(migrationAssistantModelId($authorizedLayout))
        ->and(migrationAssistantNumericAttribute($page, 'blueprint_id'))->toBe(migrationAssistantModelId($authorizedBlueprint))
        ->and(Page::query()->withoutGlobalScopes()->where('site_id', $otherSite->getKey())->count())->toBe(0);
});

it('rejects a layout that does not belong to the authorized target site', function (): void {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $foreignLayout = Layout::factory()->site($otherSite)->create();
    $type = Blueprint::factory()->page()->create();
    $preview = new ExternalImportPreview(
        target: 'page',
        creates: 1,
        skips: 0,
        rows: [['row' => 1, 'action' => 'create', 'attributes' => ['name' => 'Rejected import']]],
    );

    expect(fn (): mixed => ExecuteExternalPageImportAction::run(
        $preview,
        migrationAssistantExternalTarget($site, $foreignLayout, $type),
    ))->toThrow(RuntimeException::class, 'layout');
});

function migrationAssistantModelId(Model $model): int
{
    $key = $model->getKey();
    throw_unless(is_numeric($key), RuntimeException::class, 'Expected model key to be numeric.');

    return (int) $key;
}

function migrationAssistantNumericAttribute(Model $model, string $attribute): int
{
    $value = $model->getAttribute($attribute);
    throw_unless(is_numeric($value), RuntimeException::class, sprintf('Expected [%s] to be numeric.', $attribute));

    return (int) $value;
}

function migrationAssistantExternalTarget(Site $site, Layout $layout, Blueprint $blueprint): ExternalPageImportTargetData
{
    return new ExternalPageImportTargetData(
        siteId: migrationAssistantModelId($site),
        layoutId: migrationAssistantModelId($layout),
        blueprintId: migrationAssistantModelId($blueprint),
        languageId: (int) $site->language_id,
    );
}
