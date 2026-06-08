<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Str;

it('deletes created models from a rollback report and records an execution summary', function (): void {
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport([
        ['class' => Page::class, 'id' => $page->getKey()],
    ], executedAt: now()->addMinute());

    $result = ExecuteImportRollbackAction::run($report);

    $report->refresh();

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(1)
        ->and($result->skipped)->toBe([])
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeFalse()
        ->and($report->summary['rollback_execution']['deleted'] ?? null)->toBe(1);
});

it('dry-runs rollback execution without deleting created models', function (): void {
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport([
        ['class' => Page::class, 'id' => $page->getKey()],
    ], executedAt: now()->addMinute());

    $result = ExecuteImportRollbackAction::run($report, dryRun: true);

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(0)
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue()
        ->and($report->refresh()->summary['rollback_execution'] ?? null)->toBeNull();
});

it('skips records edited after the import execution timestamp', function (): void {
    $page = Page::factory()->create();
    $page->forceFill(['updated_at' => now()])->saveQuietly();
    $report = migrationAssistantRollbackReport([
        ['class' => Page::class, 'id' => $page->getKey()],
    ], executedAt: now()->subDay());

    $result = ExecuteImportRollbackAction::run($report);

    expect($result->matched)->toBe(1)
        ->and($result->deleted)->toBe(0)
        ->and($result->skipped)->toBe([[
            'class' => Page::class,
            'id' => $page->getKey(),
            'reason' => 'edited_after_import',
        ]])
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue();
});

/**
 * @param  list<array{class: string, id: int|string}>  $createdModels
 */
function migrationAssistantRollbackReport(array $createdModels, DateTimeInterface $executedAt): ImportRollbackReport
{
    $session = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(),
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed,
        'source_filename' => 'pages.zip',
        'executed_at' => $executedAt,
    ]);

    return ImportRollbackReport::query()->create([
        'import_session_id' => $session->getKey(),
        'source_filename' => 'pages.zip',
        'created_models' => $createdModels,
        'summary' => [],
        'manual_instructions' => 'Rollback records.',
        'executed_at' => $executedAt,
    ]);
}
