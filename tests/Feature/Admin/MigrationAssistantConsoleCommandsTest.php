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
use Illuminate\Database\Eloquent\Model;
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

    $rows = migrationAssistantConsoleJsonRows();
    $row = $rows[0] ?? [];

    expect($exitCode)->toBe(0)
        ->and($rows)->toHaveCount(1)
        ->and($row['id'] ?? null)->toBe($session->getKey())
        ->and($row['uuid'] ?? null)->toBe($session->uuid)
        ->and($row['kind'] ?? null)->toBe('page-import')
        ->and($row['status'] ?? null)->toBe('completed')
        ->and($row['source'] ?? null)->toBe('pages.zip');
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
    $page = Page::factory()->create();
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
            createdPageIds: [migrationAssistantConsoleModelIntKey($page)],
            errors: [],
        ),
    );

    $exitCode = Artisan::call('migration-assistant:rollback-report', [
        'session' => migrationAssistantConsoleModelStringKey($session),
        '--json' => true,
    ]);

    $report = migrationAssistantConsoleJsonObject();

    expect($exitCode)->toBe(0)
        ->and($report['session_id'] ?? null)->toBe($session->getKey())
        ->and($report['source_filename'] ?? null)->toBe('pages.zip')
        ->and($report['created_models'] ?? null)->toBe([
            ['class' => Page::class, 'id' => $page->getKey()],
        ])
        ->and($report['manual_instructions'] ?? null)->toContain('roll back');
});

it('executes rollback reports from the console', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create();
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'user_id' => $actor->getKey(),
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
            createdPageIds: [migrationAssistantConsoleModelIntKey($page)],
            errors: [],
        ),
    );

    $exitCode = Artisan::call('migration-assistant:rollback-execute', [
        'session' => $session->uuid,
        '--actor' => $actor->getKey(),
        '--json' => true,
    ]);

    $result = migrationAssistantConsoleJsonObject();

    expect($exitCode)->toBe(0)
        ->and($result['matched'] ?? null)->toBe(1)
        ->and($result['deleted'] ?? null)->toBe(1)
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeFalse();
});

it('exports page packages from the console', function (): void {
    $relativeExportDirectory = 'framework/testing/console-export-' . Str::random(8);
    config()->set('migration-assistant.paths.exports', $relativeExportDirectory);

    $site = Site::factory()->hasSiteDomains()->create();
    $page = Page::factory()->recycle($site)->create();

    $exitCode = Artisan::call('migration-assistant:export', [
        '--page' => [migrationAssistantConsoleModelStringKey($page)],
        '--without-media' => true,
        '--json' => true,
    ]);

    $result = migrationAssistantConsoleJsonObject();

    expect($exitCode)->toBe(0)
        ->and($result['type'] ?? null)->toBe('page-export')
        ->and($result['count'] ?? null)->toBe(1)
        ->and(File::exists(migrationAssistantConsoleString($result['path'] ?? null)))->toBeTrue();

    File::deleteDirectory(storage_path('app/' . $relativeExportDirectory));
});

it('creates validated import sessions from the console', function (): void {
    $relativeExportDirectory = 'framework/testing/console-import-export-' . Str::random(8);
    $relativeImportDirectory = 'framework/testing/console-import-' . Str::random(8);
    config()->set('migration-assistant.paths.exports', $relativeExportDirectory);
    config()->set('migration-assistant.paths.imports', $relativeImportDirectory);

    $site = Site::factory()->hasSiteDomains()->create();
    $page = Page::factory()->recycle($site)->create();
    $archivePath = resolve(PageExportService::class)->exportPages([migrationAssistantConsoleModelIntKey($page)], new ExportOptions(
        includeMedia: false,
    ));

    $exitCode = Artisan::call('migration-assistant:import', [
        'archive' => $archivePath,
        '--workspace-name' => 'Console Import',
        '--json' => true,
    ]);

    $result = migrationAssistantConsoleJsonObject();

    expect($exitCode)->toBe(0)
        ->and($result['kind'] ?? null)->toBe('page-import')
        ->and($result['status'] ?? null)->toBe('validated')
        ->and(ImportSession::query()->where('uuid', migrationAssistantConsoleString($result['uuid'] ?? null))->exists())->toBeTrue();

    File::deleteDirectory(storage_path('app/' . $relativeExportDirectory));
    File::deleteDirectory(storage_path('app/' . $relativeImportDirectory));
});

/**
 * @return list<array<string, mixed>>
 */
function migrationAssistantConsoleJsonRows(): array
{
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        return [];
    }

    $rows = [];

    foreach ($decoded as $row) {
        if (is_array($row)) {
            $rows[] = migrationAssistantConsoleStringKeyedArray($row);
        }
    }

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function migrationAssistantConsoleJsonObject(): array
{
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? migrationAssistantConsoleStringKeyedArray($decoded) : [];
}

/**
 * @param  array<array-key, mixed>  $array
 * @return array<string, mixed>
 */
function migrationAssistantConsoleStringKeyedArray(array $array): array
{
    $normalized = [];

    foreach ($array as $key => $value) {
        if (is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

function migrationAssistantConsoleModelIntKey(Model $model): int
{
    $key = $model->getKey();

    if (is_int($key)) {
        return $key;
    }

    return is_string($key) && ctype_digit($key) ? (int) $key : 0;
}

function migrationAssistantConsoleModelStringKey(Model $model): string
{
    return migrationAssistantConsoleString($model->getKey());
}

function migrationAssistantConsoleString(mixed $value): string
{
    return is_string($value) || is_int($value) || is_float($value)
        ? (string) $value
        : '';
}
