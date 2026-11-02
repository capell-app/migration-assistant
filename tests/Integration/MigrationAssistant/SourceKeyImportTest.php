<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\SourceKeyImporter;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;

it('skips unchanged rows, updates changes, and protects local edits unless forced', function (): void {
    $importer = resolve(SourceKeyImporter::class);
    $apply = static function (User $user, array $row): User {
        if (! $user->exists) {
            $user->forceFill(User::factory()->make()->getAttributes());
        }
        $user->forceFill(['name' => $row['name']])->save();

        return $user->refresh();
    };
    $first = $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'Original'], User::class, $apply);
    expect($first->outcome)->toBe('created');
    $again = $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'Original'], User::class, $apply);
    expect($again->outcome)->toBe('skipped');
    $changed = $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'Source change'], User::class, $apply);
    expect($changed->outcome)->toBe('updated')->and(($changed->beforeImage['name'] ?? null))->toBe('Original');
    $target = User::query()->findOrFail($first->targetId);
    $target->forceFill(['name' => 'Local edit'])->save();
    $protected = $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'Source change'], User::class, $apply);
    expect($protected->outcome)->toBe('conflict')->and($target->refresh()->name)->toBe('Local edit');
    $forced = $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'Source change'], User::class, $apply, force: true);
    expect($forced->outcome)->toBe('updated')->and(($forced->beforeImage['name'] ?? null))->toBe('Local edit')->and($target->refresh()->name)->toBe('Source change');
});

it('rejects invalid source identities and missing or mismatched targets', function (): void {
    $importer = resolve(SourceKeyImporter::class);
    $apply = static function (User $user, array $row): User {
        if (! $user->exists) {
            $user->forceFill(User::factory()->make()->getAttributes());
        }
        $user->forceFill(['name' => $row['name']])->save();

        return $user->refresh();
    };
    expect(fn () => $importer->import(new ImportSession(['source_environment' => '']), 'user', '1', ['name' => 'A'], User::class, $apply))->toThrow(InvalidArgumentException::class);
    $result = $importer->import(new ImportSession(['source_environment' => 'failure-fixture']), 'user', '1', ['name' => 'A'], User::class, $apply);
    expect(fn () => $importer->import(new ImportSession(['source_environment' => 'failure-fixture']), 'user', '1', ['name' => 'B'], Page::class, static fn (?Page $page): Page => $page ?? new Page))->toThrow(LogicException::class, 'target class changed');
    User::query()->whereKey($result->targetId)->delete();
    expect(fn () => $importer->import(new ImportSession(['source_environment' => 'failure-fixture']), 'user', '1', ['name' => 'B'], User::class, $apply))->toThrow(LogicException::class, 'no longer exists');
});

it('rolls back an import callback that does not return a persisted target', function (): void {
    $importer = resolve(SourceKeyImporter::class);
    expect(fn () => $importer->import(new ImportSession(['source_environment' => 'fixture']), 'user', 'bad', ['name' => 'A'], User::class, static fn (): User => new User))->toThrow(LogicException::class, 'persisted target');
    expect(DB::table('import_source_keys')->where('source_environment', 'fixture')->count())->toBe(0);
});

it('refuses to record an existing target as newly created', function (): void {
    $existing = User::factory()->create(['name' => 'Existing']);
    $importer = resolve(SourceKeyImporter::class);
    expect(fn () => $importer->import(new ImportSession(['source_environment' => 'existing-fixture']), 'user', '1', ['name' => 'Source'], User::class, static fn (): User => $existing))
        ->toThrow(LogicException::class, 'new target');
    expect($existing->refresh()->name)->toBe('Existing')
        ->and(DB::table('import_source_keys')->where('source_environment', 'existing-fixture')->exists())->toBeFalse();
});

it('refuses a target configured on a different connection before applying a row', function (): void {
    $model = new class extends User
    {
        protected $connection = 'another-connection';
    };
    $called = false;
    $apply = static function (User $target) use (&$called): User {
        $called = true;

        return $target;
    };
    expect(fn () => resolve(SourceKeyImporter::class)->import(new ImportSession(['source_environment' => 'fixture']), 'user', '1', ['name' => 'A'], $model::class, $apply))
        ->toThrow(LogicException::class, 'same database connection');
    expect($called)->toBeFalse();
});

it('isolates source keys by session site and refuses targets from another site', function (): void {
    $firstSite = Site::factory()->create();
    $secondSite = Site::factory()->create();
    $firstSession = new ImportSession(['source_environment' => 'sites', 'target_type' => 'site', 'target_id' => $firstSite->id]);
    $secondSession = new ImportSession(['source_environment' => 'sites', 'target_type' => 'site', 'target_id' => $secondSite->id]);
    $apply = static function (Page $page, array $row): Page {
        if (! $page->exists) {
            $page->forceFill(Page::factory()->make(['site_id' => $row['site_id']])->getAttributes());
        }
        $page->forceFill(['name' => $row['name']])->save();

        return $page;
    };
    $importer = resolve(SourceKeyImporter::class);
    $first = $importer->import($firstSession, 'page', '1', ['site_id' => $firstSite->id, 'name' => 'First'], Page::class, $apply);
    $second = $importer->import($secondSession, 'page', '1', ['site_id' => $secondSite->id, 'name' => 'Second'], Page::class, $apply);
    expect($first->targetId)->not->toBe($second->targetId);
    $importer->import($secondSession, 'page', '1', ['site_id' => $secondSite->id, 'name' => 'Changed'], Page::class, $apply);
    expect(Page::withoutGlobalScopes()->findOrFail($first->targetId)->name)->toBe('First');
    expect(fn () => $importer->import($firstSession, 'page', 'wrong', ['site_id' => $secondSite->id, 'name' => 'Wrong'], Page::class, $apply))->toThrow(LogicException::class, 'site');
    $firstMapping = $first->mapping ?? throw new LogicException('Missing mapping.');
    DB::table('import_source_keys')->where('id', $firstMapping['id'])->update(['target_id' => (string) $second->targetId]);
    expect(fn () => $importer->import($firstSession, 'page', '1', ['site_id' => $firstSite->id, 'name' => 'Intrusion'], Page::class, $apply, force: true))->toThrow(LogicException::class, 'site');
    expect(Page::withoutGlobalScopes()->findOrFail($second->targetId)->name)->toBe('Changed');
});
