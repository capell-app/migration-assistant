<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\MigrationAssistant\Actions\PruneImportEvidenceAction;
use Capell\MigrationAssistant\Console\Commands\PruneImportEvidenceCommand;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->fakeMigrationAssistantLocalStorage();
    config()->set('migration-assistant.disk', 'local');
});

/** @param array<string, mixed> $attributes */
function retentionSession(array $attributes = []): ImportSession
{
    $session = new ImportSession;
    $session->forceFill(array_merge([
        'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Failed,
        'updated_at' => now()->subDays(31),
        'source_package_path' => 'migration-assistant/imports/' . Str::uuid() . '.zip',
    ], $attributes));
    $session->save();

    return $session;
}

it('previews expired archives and reports storage without deleting anything', function (): void {
    $expired = retentionSession();
    $recent = retentionSession(['updated_at' => now()]);
    Storage::disk('local')->put(retentionArchivePath($expired), '12345');
    Storage::disk('local')->put(retentionArchivePath($recent), '123');

    $report = PruneImportEvidenceAction::run();

    expect($report->sessions)->toBe(2)->and($report->archiveBytes)->toBe(8)
        ->and($report->eligibleSessionIds)->toBe([$expired->id])
        ->and($report->eligibleBytes)->toBe(5)->and($report->pruned)->toBe(0)
        ->and($expired->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists(retentionArchivePath($expired));
});

it('requires an explicit prune flag and honours configured retention', function (): void {
    $expired = retentionSession(['updated_at' => now()->subDays(8)]);
    Storage::disk('local')->put(retentionArchivePath($expired), 'archive');
    expect(PruneImportEvidenceAction::run()->eligibleSessionIds)->toBe([]);
    config()->set('migration-assistant.retention.days', 7);
    expect(Artisan::call('migration-assistant:prune', ['--json' => true]))->toBe(0)
        ->and($expired->fresh())->not->toBeNull();
    expect(Artisan::call('migration-assistant:prune', ['--prune' => true, '--json' => true]))->toBe(0)
        ->and($expired->fresh())->toBeNull();
    Storage::disk('local')->assertMissing(retentionArchivePath($expired));
});

it('protects active imports legal holds and unresolved recovery evidence', function (): void {
    $held = retentionSession(['legal_hold' => true]);
    $active = retentionSession(['status' => ImportSessionStatus::Running]);
    $partial = retentionSession(['result_summary' => ['created_page_ids' => [123]]]);
    $unresolved = retentionSession();
    $report = $unresolved->rollbackReports()->create(['created_models' => [['class' => Page::class, 'id' => 123]], 'manual_instructions' => 'Recover']);
    foreach ([$held, $active, $partial, $unresolved] as $session) {
        Storage::disk('local')->put(retentionArchivePath($session), 'archive');
    }

    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(0)
        ->and(ImportSession::query()->count())->toBe(4)->and($report->fresh())->not->toBeNull();
    Storage::disk('local')->assertExists(retentionArchivePath($held));
});

it('prunes resolved reports and audits coherently and tolerates missing files and retries', function (): void {
    $session = retentionSession();
    $report = $session->rollbackReports()->create(['created_models' => [], 'manual_instructions' => 'None']);
    ImportRollbackAudit::query()->create(['import_rollback_report_id' => $report->id, 'dry_run' => true, 'outcome' => 'completed', 'matched' => 0, 'deleted' => 0, 'skipped' => []]);
    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(1)
        ->and(ImportRollbackReport::query()->count())->toBe(0)
        ->and(ImportRollbackAudit::query()->count())->toBe(0)
        ->and(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(0);
});

it('retains all evidence after a failed file delete and succeeds on a later retry', function (): void {
    $session = retentionSession();
    $report = $session->rollbackReports()->create(['created_models' => [], 'manual_instructions' => 'None']);
    $disk = Storage::disk('local');
    $disk->put(retentionArchivePath($session), 'archive');

    $failingDisk = Mockery::mock($disk);
    $failingDisk->shouldReceive('delete')->with(retentionArchivePath($session))->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($failingDisk);

    expect(PruneImportEvidenceAction::run(prune: true)->failedSessionIds)->toBe([$session->id])
        ->and($session->fresh())->not->toBeNull()->and($report->fresh())->not->toBeNull()
        ->and($disk->exists(retentionArchivePath($session)))->toBeTrue();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);
    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(1)
        ->and($report->fresh())->toBeNull();
});

it('retains edited rollback skips but prunes fully resolved partial imports', function (): void {
    $session = retentionSession(['result_summary' => ['created_page_ids' => [123]]]);
    $report = $session->rollbackReports()->create([
        'created_models' => [['class' => Page::class, 'id' => 123]],
        'manual_instructions' => 'Recover',
        'summary' => ['rollback_execution' => ['deleted' => 0, 'skipped' => [['reason' => 'changed']]]],
    ]);
    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(0);
    $report->forceFill(['summary' => ['rollback_execution' => ['deleted' => 0, 'skipped' => [['reason' => 'missing']]]]])->save();
    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(1);
});

it('retains shared archives and unmanaged paths rather than deleting other data', function (): void {
    $shared = retentionSession();
    $other = retentionSession(['legal_hold' => true, 'source_package_path' => $shared->source_package_path]);
    $outside = retentionSession(['source_package_path' => 'private/unrelated.zip']);
    $working = retentionSession(['working_dir' => '/tmp/legacy-working']);
    Storage::disk('local')->put(retentionArchivePath($shared), 'archive');
    Storage::disk('local')->put(retentionArchivePath($outside), 'private');
    expect(PruneImportEvidenceAction::run(prune: true)->failedSessionIds)->toBe([$shared->id, $outside->id, $working->id])
        ->and(ImportSession::query()->count())->toBe(4);
    Storage::disk('local')->assertExists(retentionArchivePath($shared));
    Storage::disk('local')->assertExists(retentionArchivePath($outside));
});

function retentionArchivePath(ImportSession $session): string
{
    $path = $session->source_package_path;
    if (! is_string($path) || $path === '') {
        throw new UnexpectedValueException('Expected a fixture archive path.');
    }

    return $path;
}

it('reports preview details and returns a failing command status for unmanaged evidence', function (): void {
    $session = retentionSession();
    Storage::disk('local')->put(retentionArchivePath($session), '12345');
    expect(Artisan::call('migration-assistant:prune'))->toBe(0)
        ->and(Artisan::output())->toContain('Preview (30 days)', '5 archive bytes', 'Eligible session: ' . $session->id)
        ->and((new PruneImportEvidenceCommand)->getDescription())->toContain('Preview import evidence');
    retentionSession(['working_dir' => '/tmp/legacy']);
    expect(Artisan::call('migration-assistant:prune', ['--prune' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('evidence retained');
});

it('counts shared archive storage once', function (): void {
    $session = retentionSession();
    retentionSession(['legal_hold' => true, 'source_package_path' => $session->source_package_path]);
    Storage::disk('local')->put(retentionArchivePath($session), '12345');
    expect(PruneImportEvidenceAction::run()->archiveBytes)->toBe(5);
});

it('rolls database evidence back after a vetoed session delete and can retry with the archive already missing', function (): void {
    $session = retentionSession();
    $report = $session->rollbackReports()->create(['created_models' => [], 'manual_instructions' => 'None']);
    Storage::disk('local')->put(retentionArchivePath($session), 'archive');
    $guard = new class
    {
        public bool $blocked = true;
    };
    ImportSession::deleting(static fn (ImportSession $deleting): ?bool => $guard->blocked && $deleting->id === $session->id ? false : null);
    $result = PruneImportEvidenceAction::run(prune: true);
    expect($result->pruned)->toBe(0)->and($result->failedSessionIds)->toBe([$session->id])
        ->and($session->fresh())->not->toBeNull()->and($report->fresh())->not->toBeNull();
    $guard->blocked = false;
    expect(PruneImportEvidenceAction::run(prune: true)->pruned)->toBe(1)
        ->and($report->fresh())->toBeNull();
});
