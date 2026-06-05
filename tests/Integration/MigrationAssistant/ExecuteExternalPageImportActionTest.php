<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ExternalImportPreviewBuilder;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

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
        [
            'layout_id' => $layout->getKey(),
            'blueprint_id' => $type->getKey(),
            'site_id' => $site->getKey(),
        ],
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

it('rejects external previews before writing when required page defaults are missing', function (): void {
    $preview = (new ExternalImportPreviewBuilder)->build(new ExternalImportReadResult(
        sourceType: 'csv',
        columns: ['title'],
        rows: [['title' => 'Missing defaults']],
        suggestedTarget: 'page',
    ));

    ExecuteExternalPageImportAction::run($preview);
})->throws(RuntimeException::class, 'missing required Capell page attributes');

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
        ExecuteExternalPageImportAction::run($preview, [
            'layout_id' => 999999,
            'blueprint_id' => $type->getKey(),
            'site_id' => $site->getKey(),
        ]);
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('layout_id')
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
            return new ImportExecutionReport(
                pagesCreated: 1,
                pagesSkipped: 0,
                createdPageIds: [123],
                errors: ['Second row failed.'],
            );
        }
    });

    $result = ExecuteExternalPageImportAction::run($preview, [
        'layout_id' => $layout->getKey(),
        'blueprint_id' => $type->getKey(),
        'site_id' => $site->getKey(),
    ]);

    $rollbackReport = ImportRollbackReport::query()
        ->where('import_session_id', $result->session->getKey())
        ->firstOrFail();

    $summary = $rollbackReport->summary;
    throw_unless(is_array($summary), RuntimeException::class, 'Expected rollback report summary.');

    expect($result->session->status)->toBe(ImportSessionStatus::Failed)
        ->and($rollbackReport->created_models)->toBe([
            ['class' => Page::class, 'id' => 123],
        ])
        ->and($summary['errors'])->toBe(['Second row failed.']);
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
