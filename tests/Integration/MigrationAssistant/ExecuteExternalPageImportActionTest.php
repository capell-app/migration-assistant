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
        ->and((int) $page->getAttribute('layout_id'))->toBe((int) $layout->getKey())
        ->and((int) $page->getAttribute('blueprint_id'))->toBe((int) $type->getKey())
        ->and((int) $page->getAttribute('site_id'))->toBe((int) $site->getKey())
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

    expect()->fail('Expected external execution to reject missing page references.');
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

    expect($result->session->status)->toBe(ImportSessionStatus::Failed)
        ->and($rollbackReport->created_models)->toBe([
            ['class' => Page::class, 'id' => 123],
        ])
        ->and($rollbackReport->summary['errors'])->toBe(['Second row failed.']);
});
