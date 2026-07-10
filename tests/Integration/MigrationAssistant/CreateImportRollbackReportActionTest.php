<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Illuminate\Support\Str;

it('uses mysql-safe foreign key names for import rollback reports', function (): void {
    $migration = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/2026_05_10_190859_02_create_import_rollback_reports_table.php');

    expect($migration)
        ->toContain("Schema::create('import_rollback_reports'")
        ->toContain("indexName: 'import_rollback_reports_session_fk'")
        ->toContain("indexName: 'import_rollback_reports_user_fk'")
        ->toContain("'import_rollback_reports_session_executed_idx'")
        ->and(strlen('import_rollback_reports_session_fk'))->toBeLessThanOrEqual(64)
        ->and(strlen('import_rollback_reports_user_fk'))->toBeLessThanOrEqual(64)
        ->and(strlen('import_rollback_reports_session_executed_idx'))->toBeLessThanOrEqual(64);
});

it('includes created site and domain records in rollback reports', function (): void {
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::SiteImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'site.zip',
        'executed_at' => now(),
    ]);

    $site = Site::factory()->create();
    $siteDomain = SiteDomain::factory()->for($site)->create();
    $page = Page::factory()->for($site)->create();
    $report = new ImportExecutionReport(
        pagesCreated: 1,
        pagesSkipped: 0,
        createdPageIds: [$page->getKey()],
        errors: [],
        createdSiteIds: [$site->getKey()],
        createdSiteDomainIds: [$siteDomain->getKey()],
    );

    $rollbackReport = CreateImportRollbackReportAction::run($session, $report);
    $summary = migrationAssistantSummary($rollbackReport->summary);

    expect($rollbackReport->created_models)->toBe([
        ['class' => Page::class, 'id' => $page->getKey()],
        ['class' => Site::class, 'id' => $site->getKey()],
        ['class' => SiteDomain::class, 'id' => $siteDomain->getKey()],
    ])
        ->and($summary['created_site_ids'] ?? null)->toBe([$site->getKey()])
        ->and($summary['created_site_domain_ids'] ?? null)->toBe([$siteDomain->getKey()])
        ->and($rollbackReport->provenance_signature)->not->toBeEmpty()
        ->and($rollbackReport->provenance['entries'] ?? null)->toHaveCount(3);
});

it('creates an import rollback report from an execution report', function (): void {
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'source_package_checksum' => 'sha256-example',
        'executed_at' => now(),
    ]);

    $page = Page::factory()->create();
    $report = new ImportExecutionReport(
        pagesCreated: 1,
        pagesSkipped: 0,
        createdPageIds: [$page->getKey()],
        errors: [],
        pageUrlsCreated: 2,
        mediaReassigned: 1,
    );

    $rollbackReport = CreateImportRollbackReportAction::run($session, $report);
    $summary = migrationAssistantSummary($rollbackReport->summary);

    expect($rollbackReport->import_session_id)->toBe($session->getKey())
        ->and($rollbackReport->source_filename)->toBe('pages.zip')
        ->and($rollbackReport->created_models)->toBe([
            ['class' => Page::class, 'id' => $page->getKey()],
        ])
        ->and($summary['page_urls_created'] ?? null)->toBe(2)
        ->and($rollbackReport->manual_instructions)->toContain('roll back');
});
