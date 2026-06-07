<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Data\ExportOptions;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Export\PageExportService;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('shows import session status from the console', function (): void {
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'executed_at' => now(),
    ]);

    $exitCode = Artisan::call('migration-assistant:status', [
        'session' => $session->uuid,
        '--json' => true,
    ]);

    $rows = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($session->getKey())
        ->and($rows[0]['uuid'])->toBe($session->uuid)
        ->and($rows[0]['kind'])->toBe('page-import')
        ->and($rows[0]['status'])->toBe('completed')
        ->and($rows[0]['source'])->toBe('pages.zip');
});

it('returns a failure code when a requested import session cannot be found', function (): void {
    $exitCode = Artisan::call('migration-assistant:status', [
        'session' => 'missing-session',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('missing-session');
});

it('shows rollback report data from the console', function (): void {
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'source_package_checksum' => 'sha256-example',
        'executed_at' => now(),
    ]);

    CreateImportRollbackReportAction::run(
        $session,
        new ImportExecutionReport(
            pagesCreated: 1,
            pagesSkipped: 0,
            createdPageIds: [123],
            errors: [],
        ),
    );

    $exitCode = Artisan::call('migration-assistant:rollback-report', [
        'session' => (string) $session->getKey(),
        '--json' => true,
    ]);

    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($report['session_id'])->toBe($session->getKey())
        ->and($report['source_filename'])->toBe('pages.zip')
        ->and($report['created_models'])->toBe([
            ['class' => Page::class, 'id' => 123],
        ])
        ->and($report['manual_instructions'])->toContain('roll back');
});

it('executes rollback reports from the console', function (): void {
    $page = Page::factory()->create();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'executed_at' => now()->addMinute(),
    ]);

    CreateImportRollbackReportAction::run(
        $session,
        new ImportExecutionReport(
            pagesCreated: 1,
            pagesSkipped: 0,
            createdPageIds: [(int) $page->getKey()],
            errors: [],
        ),
    );

    $exitCode = Artisan::call('migration-assistant:rollback-execute', [
        'session' => $session->uuid,
        '--json' => true,
    ]);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result['matched'])->toBe(1)
        ->and($result['deleted'])->toBe(1)
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeFalse();
});

it('exports page packages from the console', function (): void {
    $relativeExportDirectory = 'framework/testing/console-export-' . Str::random(8);
    config()->set('migration-assistant.paths.exports', $relativeExportDirectory);

    $site = Site::factory()->hasSiteDomains()->create();
    $page = Page::factory()->recycle($site)->create();

    $exitCode = Artisan::call('migration-assistant:export', [
        '--page' => [(string) $page->getKey()],
        '--without-media' => true,
        '--json' => true,
    ]);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result['type'])->toBe('page-export')
        ->and($result['count'])->toBe(1)
        ->and(File::exists($result['path']))->toBeTrue();

    File::deleteDirectory(storage_path('app/' . $relativeExportDirectory));
});

it('creates validated import sessions from the console', function (): void {
    $relativeExportDirectory = 'framework/testing/console-import-export-' . Str::random(8);
    $relativeImportDirectory = 'framework/testing/console-import-' . Str::random(8);
    config()->set('migration-assistant.paths.exports', $relativeExportDirectory);
    config()->set('migration-assistant.paths.imports', $relativeImportDirectory);

    $site = Site::factory()->hasSiteDomains()->create();
    $page = Page::factory()->recycle($site)->create();
    $archivePath = resolve(PageExportService::class)->exportPages([(int) $page->getKey()], new ExportOptions(
        includeMedia: false,
    ));

    $exitCode = Artisan::call('migration-assistant:import', [
        'archive' => $archivePath,
        '--workspace-name' => 'Console Import',
        '--json' => true,
    ]);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($result['kind'])->toBe('page-import')
        ->and($result['status'])->toBe('validated')
        ->and(ImportSession::query()->where('uuid', $result['uuid'])->exists())->toBeTrue();

    File::deleteDirectory(storage_path('app/' . $relativeExportDirectory));
    File::deleteDirectory(storage_path('app/' . $relativeImportDirectory));
});
