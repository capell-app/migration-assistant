<?php

declare(strict_types=1);

use Capell\Blog\Models\Article;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\MigrationAssistant\Actions\CreateDatabaseRollbackReportAction;
use Capell\MigrationAssistant\Actions\CreateImportRollbackReportAction;
use Capell\MigrationAssistant\Actions\ExecuteImportRollbackAction;
use Capell\MigrationAssistant\Actions\RegisterDatabaseSourceLabelAction;
use Capell\MigrationAssistant\Actions\RetryImportSessionAction;
use Capell\MigrationAssistant\Data\DatabaseRollbackChange;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Data\SourceKeyImportResult;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Capell\MigrationAssistant\Support\RollbackProvenance;
use Capell\MigrationAssistant\Support\SourceKeyImporter;
use Capell\MigrationAssistant\Tests\Fixtures\FakeDatabaseImportExecutor;
use Capell\Navigation\Models\Navigation;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

    if (! is_array($provenance) || ! is_array($provenance['entries'] ?? null) || ! is_array($provenance['entries'][0] ?? null)) {
        throw new LogicException('Expected rollback provenance entries.');
    }

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
    $this->freezeTime();

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
    $unauthorizedActor->givePermissionTo(MigrationAssistantPermission::ImportSessionRollback->value);

    $result = ExecuteImportRollbackAction::run($report, actor: $unauthorizedActor);

    expect($result->matched)->toBe(0)
        ->and($result->deleted)->toBe(0)
        ->and($result->rejected)->toBeTrue()
        ->and($result->skipped[0]['reason'] ?? null)->toBe('unauthorized')
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue();
});

it('requires explicit rollback authority even when the actor may delete the target model', function (): void {
    $actor = migrationAssistantRollbackActor(canRollback: false);
    $page = Page::factory()->create();
    $report = migrationAssistantRollbackReport($page, $actor);

    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    expect($result->matched)->toBe(0)
        ->and($result->deleted)->toBe(0)
        ->and($result->rejected)->toBeTrue()
        ->and($result->skipped[0]['reason'] ?? null)->toBe('unauthorized')
        ->and(Page::query()->whereKey($page->getKey())->exists())->toBeTrue();
});

function migrationAssistantRollbackActor(bool $canRollback = true): User
{
    Permission::findOrCreate(MigrationAssistantPermission::ImportSessionRollback->value);
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('super_admin'));

    if ($canRollback) {
        $actor->givePermissionTo(MigrationAssistantPermission::ImportSessionRollback->value);
    }

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
        createdPageIds: [migrationAssistantRollbackModelKey($page)],
        errors: [],
    ));
}

function migrationAssistantRollbackModelKey(Model $model): int|string
{
    $key = $model->getKey();

    if (! is_int($key) && ! is_string($key)) {
        throw new LogicException('Expected a scalar model key.');
    }

    return $key;
}

it('restores an imported siteless user from an encrypted before-image', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create(['name' => 'Before']);
    $before = $target->getAttributes();
    $target->forceFill(['name' => 'Imported'])->save();
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [
        new DatabaseRollbackChange($target->fresh(), $before),
    ]);
    expect(DB::table('import_rollback_reports')->where('id', $report->id)->value('before_images'))->not->toContain('Before')
        ->and(data_get($report->summary, 'entities.user.updated'))->toBe(1);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->rejected)->toBeFalse()->and($result->restored)->toBe(1)->and($target->refresh()->name)->toBe('Before');
});

it('rejects tampered before-images and detects target edits even when timestamps do not change', function (string $tamper): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create(['name' => 'Before']);
    $before = $target->getAttributes();
    $target->forceFill(['name' => 'Imported'])->save();
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target->fresh(), $before)]);
    if ($tamper === 'image') {
        $before['name'] = 'Tampered';
        $report->forceFill(['before_images' => [$before]])->saveQuietly();
    } else {
        DB::table($target->getTable())->where('id', $target->getKey())->update(['name' => 'Local edit']);
    }
    $result = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor);
    expect($result->skipped[0]['reason'])->toBe($tamper === 'image' ? 'invalid_provenance' : 'edited_after_import');
    expect($target->refresh()->name)->toBe($tamper === 'image' ? 'Imported' : 'Local edit');
})->with(['image', 'target']);

it('restores the source mapping with an updated target on rollback', function (): void {
    $actor = migrationAssistantRollbackActor();
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'mapping-fixture', 'executed_at' => now(),
    ]);
    $importer = resolve(SourceKeyImporter::class);
    $apply = static function (User $user, array $row): User {
        if (! $user->exists) {
            $user->forceFill(User::factory()->make()->getAttributes());
        }
        $user->forceFill(['name' => $row['name']])->save();

        return $user->refresh();
    };
    $first = $importer->import($session, 'user', '1', ['name' => 'Before'], User::class, $apply);
    $changed = $importer->import($session, 'user', '1', ['name' => 'After'], User::class, $apply);
    $user = User::query()->findOrFail($first->targetId);
    $report = CreateDatabaseRollbackReportAction::run($session, [
        new DatabaseRollbackChange($user, $changed->beforeImage, $changed),
    ]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->skipped)->toBe([])->and($user->refresh()->name)->toBe('Before');
    $repeat = $importer->import($session, 'user', '1', ['name' => 'Before'], User::class, $apply);
    expect($repeat->outcome)->toBe('skipped');
});

it('rolls back created and updated database entities', function (string $entity, bool $updated): void {
    $actor = migrationAssistantRollbackActor();
    if ($entity === 'navigation') {
        (require __DIR__ . '/../../../../navigation/database/migrations/2026_05_10_190860_01_create_navigations_table.php')->up();
    }
    if ($entity === 'article') {
        app()->register(FrontendServiceProvider::class);
        (require __DIR__ . '/../../../../blog/database/migrations/2026_05_10_190842_01_create_articles_table.php')->up();
    }
    $model = match ($entity) {
        'user' => User::factory()->create(),
        'role' => Role::query()->create(['name' => 'imported-test-role', 'guard_name' => 'web']),
        'setting' => SettingsProperty::query()->create(['group' => 'test', 'name' => 'rollback', 'payload' => '"before"', 'locked' => false]),
        'navigation' => Navigation::factory()->create(),
        'media' => Media::factory()->model(Page::factory()->create())->create(),
        'article' => Article::withoutEvents(static fn () => Article::query()->create(['name' => 'Before', 'site_id' => Site::factory()->create()->getKey(), 'blueprint_id' => Blueprint::factory()->create()->getKey(), 'layout_id' => Layout::factory()->create()->getKey()])),
        default => throw new LogicException('Unknown entity.'),
    };
    $field = match ($entity) {
        'setting' => 'payload',
        default => 'name',
    };
    $before = $updated ? $model->getAttributes() : null;
    if ($updated) {
        $model->forceFill([$field => $entity === 'setting' ? '"after"' : 'After import'])->save();
        $model->refresh();
    }
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [($updated ? new DatabaseRollbackChange($model, $before) : migrationAssistantCreatedChange($model, $session))]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->skipped)->toBe([]);
    $current = $model->newQueryWithoutScopes()->find($model->getKey());
    if ($updated) {
        expect($current)->not->toBeNull()->and($current->getAttributes()[$field])->toBe($before[$field]);
    } else {
        // A created row is purged, even for a soft-deleting model, so its unique keys are free for a re-import.
        expect($current)->toBeNull();
    }
})->with(['user', 'role', 'setting', 'navigation', 'media', 'article'])->with([true, false]);

it('runs a fake database executor through preview, reruns, edit protection and rollback', function (): void {
    config(['database.connections.source_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $sourceDb = DB::connection('source_fixture');
    $sourceDb->getSchemaBuilder()->create('fake_import_rows', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    try {
        $sourceDb->table('fake_import_rows')->insert(['id' => 1, 'name' => 'Original']);
        $source = DatabaseSourceManifest::preview('source_fixture', ['fake_import_rows'], 'fixture');
        resolve(RegisterDatabaseSourceLabelAction::class)->handle('fixture', 'source_fixture', false, false);
        $actor = migrationAssistantRollbackActor();
        $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Queued, 'source_environment' => 'fake-db', 'manifest' => $source->toArray()]);
        $executor = new FakeDatabaseImportExecutor;
        $executor->execute($session);
        expect($executor->outcome)->toBe('created');
        $executor->execute($session);
        expect($executor->outcome)->toBe('skipped');
        $sourceDb->table('fake_import_rows')->where('id', 1)->update(['name' => 'Changed']);
        $executor->execute($session);
        expect($executor->outcome)->toBe('updated');
        $target = User::query()->where('name', 'Changed')->firstOrFail();
        $target->forceFill(['name' => 'Local'])->save();
        $sourceDb->table('fake_import_rows')->where('id', 1)->update(['name' => 'Latest']);
        $executor->execute($session);
        expect($executor->outcome)->toBe('conflict')->and($target->refresh()->name)->toBe('Local');
        $executor->force = true;
        $executor->execute($session);
        expect($executor->outcome)->toBe('updated')->and($target->refresh()->name)->toBe('Latest');
        $report = $executor->report ?? throw new LogicException('Missing rollback report.');
        $result = ExecuteImportRollbackAction::run($report, actor: $actor);
        expect($result->skipped)->toBe([])->and($target->refresh()->name)->toBe('Local');
    } finally {
        $sourceDb->getSchemaBuilder()->dropIfExists('fake_import_rows');
    }
});

it('rejects siteless rollback for a non-global actor despite rollback permission', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture']);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($target, $session)]);
    $other = User::factory()->create();
    $other->givePermissionTo(MigrationAssistantPermission::ImportSessionRollback->value);
    $result = ExecuteImportRollbackAction::run($report, actor: $other);
    expect($result->rejected)->toBeTrue()->and($result->skipped[0]['reason'])->toBe('unauthorized')->and($target->fresh())->not->toBeNull();
});

it('does not roll back a target when a later import changed its source mapping', function (): void {
    $actor = migrationAssistantRollbackActor();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'mapping-conflict']);
    $importer = resolve(SourceKeyImporter::class);
    $apply = static function (User $user, array $row): User {
        if (! $user->exists) {
            $user->forceFill(User::factory()->make()->getAttributes());
        }
        $user->forceFill(['name' => $row['name']])->save();

        return $user->refresh();
    };
    $created = $importer->import($session, 'user', '1', ['name' => 'First'], User::class, $apply);
    $target = User::query()->findOrFail($created->targetId);
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target, null, $created)]);
    DB::table('import_source_keys')->where('id', $created->mapping['id'] ?? throw new LogicException('Missing mapping.'))->update(['source_checksum' => str_repeat('a', 64)]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->skipped[0]['reason'])->toBe('mapping_changed')->and($target->fresh())->not->toBeNull();
});

it('keeps database rollback evidence unresolved until rollback succeeds', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'retention-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($target, $session)]);
    expect(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue();
    ExecuteImportRollbackAction::run($report, actor: $actor);
    expect(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeFalse();
});

it('resumes a partially completed database rollback without repeating restored entries', function (): void {
    $actor = migrationAssistantRollbackActor();
    $first = User::factory()->create(['name' => 'Before first']);
    $second = User::factory()->create(['name' => 'Before second']);
    $beforeFirst = $first->getAttributes();
    $beforeSecond = $second->getAttributes();
    $first->forceFill(['name' => 'Imported first'])->save();
    $second->forceFill(['name' => 'Imported second'])->save();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'partial-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($first, $beforeFirst), new DatabaseRollbackChange($second, $beforeSecond)]);
    $afterSecond = $second->fresh()->getAttributes();
    $second->forceFill(['name' => 'Local edit'])->save();
    $firstResult = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($firstResult->skipped[0]['reason'])->toBe('edited_after_import')->and($first->refresh()->name)->toBe('Before first');
    DB::table($second->getTable())->where('id', $second->getKey())->update($afterSecond);
    $secondResult = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor);
    expect($secondResult->skipped)->toBe([])->and($second->refresh()->name)->toBe('Before second')
        ->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeFalse();
});

it('does not resolve rollback evidence when a model refuses deletion', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'refusal-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($target, $session)]);
    User::deleting(static fn (User $user): bool => $user->getKey() !== $target->getKey());
    try {
        expect(fn () => ExecuteImportRollbackAction::run($report, actor: $actor))->toThrow(UnexpectedValueException::class, 'could not be deleted');
        expect($target->fresh())->not->toBeNull()->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue();
    } finally {
        Event::forget('eloquent.deleting: ' . User::class);
    }
});

it('does not resolve rollback evidence when a model refuses restoration', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create(['name' => 'Before']);
    $before = $target->getAttributes();
    $target->forceFill(['name' => 'Imported'])->save();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'refusal-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target, $before)]);
    User::updating(static fn (User $user): bool => $user->getKey() !== $target->getKey());
    try {
        expect(fn () => ExecuteImportRollbackAction::run($report, actor: $actor))->toThrow(UnexpectedValueException::class, 'could not be restored');
        expect($target->fresh()->name)->toBe('Imported')->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue();
    } finally {
        Event::forget('eloquent.updating: ' . User::class);
    }
});

it('rejects a database report with altered signed rollback progress', function (): void {
    $actor = migrationAssistantRollbackActor();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'tamper-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($first, $session), migrationAssistantCreatedChange($second, $session)]);
    $second->forceFill(['name' => 'Local'])->save();
    ExecuteImportRollbackAction::run($report, actor: $actor);
    $summary = $report->refresh()->summary;
    if (! is_array($summary) || ! is_array($summary['rollback_execution'] ?? null)) {
        throw new LogicException('Expected rollback progress.');
    }
    $summary['rollback_execution']['resolved_indices'] = [0, 1];
    $report->forceFill(['summary' => $summary])->saveQuietly();
    $result = ExecuteImportRollbackAction::run($report->refresh(), actor: $actor);
    expect($result->rejected)->toBeTrue()->and($result->skipped[0]['reason'])->toBe('invalid_provenance')
        ->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue();
});

it('refuses duplicate and unpersisted targets when making database rollback reports', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'invalid-db']);
    $change = migrationAssistantCreatedChange($target, $session);
    expect(fn () => CreateDatabaseRollbackReportAction::run($session, [$change, $change]))->toThrow(LogicException::class, 'Duplicate rollback target');
    expect(fn () => CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange(new User)]))->toThrow(LogicException::class, 'must exist');
    expect($session->rollbackReports()->count())->toBe(0);
});

it('previews database rollback without restoring a target or resolving evidence', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create(['name' => 'Before']);
    $before = $target->getAttributes();
    $target->forceFill(['name' => 'Imported'])->save();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'dry-run-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target, $before)]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor, dryRun: true);
    expect($result->matched)->toBe(1)->and($result->restored)->toBe(0)->and($target->refresh()->name)->toBe('Imported')
        ->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue();
});

it('reports missing database rollback targets without treating them as restored', function (): void {
    $actor = migrationAssistantRollbackActor();
    $target = User::factory()->create();
    $session = ImportSession::query()->create(['user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'missing-db']);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($target, $session)]);
    $target->delete();
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->skipped[0]['reason'])->toBe('missing')->and($result->restored)->toBe(0)
        ->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeFalse();
});

it('rejects an existing page without proof that the session created it', function (): void {
    $actor = migrationAssistantRollbackActor();
    $page = Page::factory()->create()->fresh();
    $session = ImportSession::query()->create(['user_id' => $actor->id, 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'unproven']);
    expect(fn () => CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($page)]))->toThrow(LogicException::class, 'created');
    expect($session->rollbackReports()->count())->toBe(0)->and($page->fresh())->not->toBeNull();
});

it('resolves missing database targets and clears their mapping for future imports', function (bool $updated): void {
    $actor = migrationAssistantRollbackActor();
    $session = ImportSession::query()->create(['user_id' => $actor->id, 'kind' => ImportSessionKind::PageImport, 'status' => ImportSessionStatus::Completed, 'source_environment' => 'missing-mapped']);
    $importer = resolve(SourceKeyImporter::class);
    $apply = static function (User $user, array $row): User {
        if (! $user->exists) {
            $user->forceFill(User::factory()->make()->getAttributes());
        }
        $user->forceFill(['name' => $row['name']])->save();

        return $user;
    };
    $created = $importer->import($session, 'user', '1', ['name' => 'First'], User::class, $apply);
    $change = $updated ? $importer->import($session, 'user', '1', ['name' => 'Second'], User::class, $apply) : $created;
    $target = User::query()->findOrFail($created->targetId);
    $mapping = $change->mapping ?? throw new LogicException('Missing mapping.');
    $report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target, $change->beforeImage, $change)]);
    $target->delete();
    ExecuteImportRollbackAction::run($report, actor: $actor, dryRun: true);
    expect(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeTrue()
        ->and(DB::table('import_source_keys')->where('id', $mapping['id'])->exists())->toBeTrue();
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);
    expect($result->skipped[0]['reason'])->toBe('missing')->and($result->restored)->toBe(0)
        ->and(RetryImportSessionAction::hasUnresolvedCreatedModels($session))->toBeFalse()
        ->and(DB::table('import_source_keys')->where('id', $mapping['id'])->exists())->toBeFalse();
    expect($importer->import($session, 'user', '1', ['name' => 'Again'], User::class, $apply)->outcome)->toBe('created');
})->with([false, true]);

it('uses configured optional rollback target classes', function (): void {
    config()->set('migration-assistant.rollback_targets', ['custom_user' => User::class]);
    $user = User::factory()->create();
    expect(RollbackProvenance::isAllowedType('custom_user'))->toBeTrue()
        ->and(RollbackProvenance::findEntryModel(['type' => 'custom_user', 'id' => $user->id])?->getKey())->toBe($user->id);
});

it('rolls back an imported Blog article through its default rollback target', function (): void {
    app()->register(FrontendServiceProvider::class);
    (require __DIR__ . '/../../../../blog/database/migrations/2026_05_10_190842_01_create_articles_table.php')->up();
    $actor = migrationAssistantRollbackActor();
    $article = Article::withoutEvents(static fn () => Article::query()->create(['name' => 'Imported', 'site_id' => Site::factory()->create()->getKey(), 'blueprint_id' => Blueprint::factory()->create()->getKey(), 'layout_id' => Layout::factory()->create()->getKey()]));
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);

    expect(RollbackProvenance::isAllowedType('article'))->toBeTrue()
        ->and(RollbackProvenance::findEntryModel(['type' => 'article', 'id' => $article->getKey()]))->toBeInstanceOf(Article::class);

    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($article, $session)]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    expect($result->skipped)->toBe([])
        ->and(Article::query()->withoutGlobalScopes()->whereKey($article->getKey())->exists())->toBeFalse();
});

it('rolls back an imported Navigation menu through its default rollback target', function (): void {
    (require __DIR__ . '/../../../../navigation/database/migrations/2026_05_10_190860_01_create_navigations_table.php')->up();
    $actor = migrationAssistantRollbackActor();
    $navigation = Navigation::factory()->create();
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);

    expect(RollbackProvenance::isAllowedType('navigation'))->toBeTrue()
        ->and(RollbackProvenance::findEntryModel(['type' => 'navigation', 'id' => $navigation->getKey()]))->toBeInstanceOf(Navigation::class);

    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($navigation, $session)]);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    expect($result->skipped)->toBe([])
        ->and(Navigation::query()->withoutGlobalScopes()->whereKey($navigation->getKey())->exists())->toBeFalse();
});

function migrationAssistantCreatedChange(Model $model, ImportSession $session): DatabaseRollbackChange
{
    $key = migrationAssistantRollbackModelKey($model);
    $checksum = str_repeat('a', 64);
    $id = DB::table('import_source_keys')->insertGetId([
        'site_scope' => 'global', 'source_environment' => $session->source_environment, 'entity_type' => 'fixture',
        'source_key_hash' => hash('sha256', (string) Str::uuid()), 'target_class' => $model::class,
        'target_id' => (string) $key, 'source_checksum' => $checksum, 'target_checksum' => $checksum,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $mapping = ['id' => $id, 'target_class' => $model::class, 'target_id' => (string) $key,
        'source_checksum' => $checksum, 'target_checksum' => $checksum,
        'previous_source_checksum' => null, 'previous_target_checksum' => null];

    return new DatabaseRollbackChange($model, null, new SourceKeyImportResult('created', $key, null, $mapping));
}

it('purges a created soft-deleting row on rollback so the same key can be imported again', function (): void {
    $actor = migrationAssistantRollbackActor();
    (require __DIR__ . '/../../../../navigation/database/migrations/2026_05_10_190860_01_create_navigations_table.php')->up();
    $navigation = Navigation::factory()->create(['key' => 'main']);
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($navigation, $session)]);

    ExecuteImportRollbackAction::run($report, actor: $actor);

    expect(Navigation::onlyTrashed()->where('key', 'main')->exists())->toBeFalse()
        ->and(Navigation::withTrashed()->where('key', 'main')->exists())->toBeFalse();
});

it('journals created role assignments and removes only those on rollback', function (): void {
    $actor = migrationAssistantRollbackActor();
    $user = User::factory()->create();
    $kept = Role::findOrCreate('kept-role');
    $imported = Role::findOrCreate('imported-role');
    $user->assignRole($kept);
    $user->assignRole($imported);
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);

    $report = CreateDatabaseRollbackReportAction::run($session, [], [
        migrationAssistantRolePair($imported, $user),
    ]);

    expect(data_get($report->summary, 'entities.model_has_roles.created'))->toBe(1);
    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    expect($result->rejected)->toBeFalse()->and($result->deleted)->toBe(1)->and($result->skipped)->toBe([])
        ->and($user->fresh()->roles->pluck('name')->all())->toContain('kept-role')->not->toContain('imported-role');
    expect(ExecuteImportRollbackAction::run($report->refresh(), actor: $actor)->deleted)->toBe(0);
});

it('rejects a role assignment entry that was altered after signing', function (): void {
    $actor = migrationAssistantRollbackActor();
    $user = User::factory()->create();
    $role = Role::findOrCreate('imported-role');
    $user->assignRole($role);
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [], [
        migrationAssistantRolePair($role, $user),
    ]);
    $provenance = $report->provenance;
    $entries = is_array($provenance) ? ($provenance['entries'] ?? null) : null;
    if (! is_array($provenance) || ! is_array($entries) || ! is_array($entries[0] ?? null)) {
        throw new LogicException('Expected rollback provenance entries.');
    }
    $entries[0]['role_id'] = 999;
    $provenance['entries'] = $entries;
    $report->forceFill(['provenance' => $provenance])->saveQuietly();

    expect(ExecuteImportRollbackAction::run($report->refresh(), actor: $actor)->rejected)->toBeTrue()
        ->and($user->fresh()->hasRole('imported-role'))->toBeTrue();
});

/** @return array{role_id: int|string, model_type: string, model_id: int|string} */
function migrationAssistantRolePair(Spatie\Permission\Contracts\Role $role, User $user): array
{
    return ['role_id' => migrationAssistantRollbackModelKey(Role::query()->where('name', $role->name)->firstOrFail()), 'model_type' => $user->getMorphClass(), 'model_id' => migrationAssistantRollbackModelKey($user)];
}

it('removes only the journalled team assignment of a role pair when permission teams are enabled', function (): void {
    $user = User::factory()->create();
    $role = Role::findOrCreate('team-role');
    Schema::dropIfExists('model_has_roles');
    Schema::create('model_has_roles', static function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->unsignedBigInteger('team_id')->nullable();
    });
    $actor = migrationAssistantRollbackActor();
    config(['permission.teams' => true, 'permission.column_names.team_foreign_key' => 'team_id']);
    foreach ([1, 2] as $team) {
        DB::table('model_has_roles')->insert(['role_id' => $role->getKey(), 'model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'team_id' => $team]);
    }
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [], [[...migrationAssistantRolePair($role, $user), 'team_id' => 1]]);

    $result = ExecuteImportRollbackAction::run($report, actor: $actor);

    expect($result->deleted)->toBe(1)
        ->and(DB::table('model_has_roles')->where('role_id', $role->getKey())->pluck('team_id')->all())->toBe([2]);
});

it('still removes a role assignment that was re-created after the import, because a pivot row has no edit guard', function (): void {
    $actor = migrationAssistantRollbackActor();
    $user = User::factory()->create();
    $role = Role::findOrCreate('recreated-role');
    $user->assignRole($role);
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [], [migrationAssistantRolePair($role, $user)]);
    $user->removeRole($role);
    $user->assignRole($role);

    expect(ExecuteImportRollbackAction::run($report, actor: $actor)->deleted)->toBe(1)
        ->and($user->fresh()?->hasRole('recreated-role'))->toBeFalse();
});

it('soft-deletes instead of purging a created row that something added after the import still references', function (): void {
    $actor = migrationAssistantRollbackActor();
    (require __DIR__ . '/../../../../navigation/database/migrations/2026_05_10_190860_01_create_navigations_table.php')->up();
    Schema::create('purge_probe', static function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('owner_type');
        $table->unsignedBigInteger('owner_id');
    });
    $navigation = Navigation::factory()->create(['key' => 'referenced']);
    DB::table('purge_probe')->insert(['owner_type' => Navigation::class, 'owner_id' => $navigation->getKey()]);
    $session = ImportSession::query()->create([
        'user_id' => $actor->getKey(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Completed, 'source_environment' => 'fixture', 'executed_at' => now(),
    ]);
    $report = CreateDatabaseRollbackReportAction::run($session, [migrationAssistantCreatedChange($navigation, $session)]);

    ExecuteImportRollbackAction::run($report, actor: $actor);

    expect(Navigation::onlyTrashed()->whereKey($navigation->getKey())->exists())->toBeTrue()
        ->and(data_get($report->refresh()->summary, 'rollback_execution.soft_deleted.0.reason'))->toBe('has_references:purge_probe');
});

it('marks the cached URLs of the rolled-back site stale', function (): void {
    $actor = migrationAssistantRollbackActor();
    CapellCore::registerPackage('capell-app/html-cache');
    CapellCore::getPackage('capell-app/html-cache')->installed = true;
    foreach (['2026_05_10_190854_01_create_cached_model_urls_table.php', '2026_05_14_000001_create_stale_cached_urls_table.php', '2026_06_07_000001_add_telemetry_to_cached_model_urls_table.php'] as $migration) {
        (require __DIR__ . '/../../../../html-cache/database/migrations/' . $migration)->up();
    }
    $page = Page::factory()->create();
    $other = Site::factory()->create();
    foreach ([$page->site_id, $other->id] as $siteId) {
        DB::table('cached_model_urls')->insert(['url' => '/c-' . $siteId, 'url_hash' => hash('sha256', '/c-' . $siteId), 'path' => '/c-' . $siteId, 'site_id' => $siteId, 'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $report = migrationAssistantRollbackReport($page, $actor);

    ExecuteImportRollbackAction::run($report, actor: $actor, dryRun: true);
    expect(DB::table('stale_cached_urls')->count())->toBe(0);
    ExecuteImportRollbackAction::run($report, actor: $actor);

    expect(DB::table('stale_cached_urls')->where('site_id', $page->site_id)->where('reason', 'import_rollback')->count())->toBe(1)
        ->and(DB::table('stale_cached_urls')->where('site_id', $other->id)->count())->toBe(0);
});
