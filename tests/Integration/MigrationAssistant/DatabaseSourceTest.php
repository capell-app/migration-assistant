<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\RegisterDatabaseSourceLabelAction;
use Capell\MigrationAssistant\Contracts\DatabaseImportSessionExecutor;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ImportSessionExecutorRegistry;
use Capell\MigrationAssistant\Tests\Fixtures\FakeDatabaseImportExecutor;
use Capell\MigrationAssistant\Tests\Fixtures\RecordingImportSessionExecutor;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

it('records a stable fingerprint and refuses execution after the source schema changes', function (): void {
    config(['database.connections.source_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $schema = DB::connection('source_fixture')->getSchemaBuilder();
    $schema->create('migration_source_fixture', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });

    try {
        $connection = 'source_fixture';
        $manifest = DatabaseSourceManifest::preview($connection, ['migration_source_fixture'], 'fixture');
        expect($manifest->toArray())->toHaveKeys(['type', 'connection', 'tables', 'schema_fingerprint', 'source_identity']);
        DatabaseSourceManifest::fromArray($manifest->toArray())->assertUnchanged();

        $schema->table('migration_source_fixture', function (Blueprint $table): void {
            $table->text('body')->nullable();
        });

        expect(fn () => $manifest->assertUnchanged())->toThrow(RuntimeException::class, 'Source database schema changed');
    } finally {
        $schema->dropIfExists('migration_source_fixture');
    }
});

it('refuses a manifest after its connection alias is repointed to an identical schema', function (): void {
    $first = tempnam(sys_get_temp_dir(), 'manifest-a-');
    $second = tempnam(sys_get_temp_dir(), 'manifest-b-');
    if ($first === false || $second === false) {
        throw new LogicException('Cannot create synthetic databases.');
    }
    try {
        config(['database.connections.source_repoint' => ['driver' => 'sqlite', 'database' => $first, 'prefix' => '']]);
        DB::purge('source_repoint');
        DB::connection('source_repoint')->statement('CREATE TABLE fixture (id INTEGER)');
        $manifest = DatabaseSourceManifest::preview('source_repoint', ['fixture'], 'fixture');
        config(['database.connections.source_repoint' => ['driver' => 'sqlite', 'database' => $second, 'prefix' => '']]);
        DB::purge('source_repoint');
        DB::connection('source_repoint')->statement('CREATE TABLE fixture (id INTEGER)');
        expect(fn () => $manifest->assertUnchanged())->toThrow(RuntimeException::class, 'Source database identity changed');
    } finally {
        DB::purge('source_repoint');
        unlink($first);
        unlink($second);
    }
});

it('redacts database query bindings from queued executor failures', function (): void {
    config(['database.connections.secret_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::connection('secret_fixture')->statement('CREATE TABLE fixture (id INTEGER)');
    $manifest = DatabaseSourceManifest::preview('secret_fixture', ['fixture'], 'fixture');
    resolve(RegisterDatabaseSourceLabelAction::class)->handle('fixture', 'secret_fixture', false, false);
    $session = ImportSession::query()->create(['kind' => ImportSessionKind::PageImport, 'source_environment' => 'synthetic', 'manifest' => $manifest->toArray()]);
    $executor = new class extends DatabaseImportSessionExecutor
    {
        #[Override]
        public function supports(ImportSession $session): bool
        {
            return true;
        }

        #[Override]
        public function canRetry(ImportSession $session): bool
        {
            return false;
        }

        #[Override]
        protected function executeDatabase(ImportSession $session, DatabaseSourceManifest $source): void
        {
            throw new QueryException('secret_fixture', 'SELECT ?', ['synthetic-secret-binding'], new PDOException('fixture error'));
        }
    };
    try {
        $executor->execute($session);
        throw new LogicException('Expected the executor to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('synthetic-secret-binding')
            ->and($exception->getPrevious())->toBeNull()
            ->and((string) $exception)->not->toContain('synthetic-secret-binding')
            ->and($session->refresh()->failure_reason)->toContain('QueryException')->not->toContain('synthetic-secret-binding');
    }
});

it('rejects malformed source manifests and unknown source tables', function (): void {
    expect(fn () => DatabaseSourceManifest::fromArray(['type' => 'database', 'connection' => 'test', 'tables' => [], 'schema_fingerprint' => 'invalid']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => DatabaseSourceManifest::preview(DB::getDefaultConnection(), ['missing_source_table'], 'fixture'))
        ->toThrow(InvalidArgumentException::class);
});

it('checks a database source even when an executor is invoked directly', function (): void {
    config(['database.connections.source_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $schema = DB::connection('source_fixture')->getSchemaBuilder();
    $schema->create('migration_direct_fixture', function (Blueprint $table): void {
        $table->id();
    });
    try {
        $manifest = DatabaseSourceManifest::preview('source_fixture', ['migration_direct_fixture'], 'fixture');
        $session = new ImportSession(['manifest' => $manifest->toArray()]);
        $executor = new class extends DatabaseImportSessionExecutor
        {
            public bool $ran = false;

            public function supports(ImportSession $session): bool
            {
                return true;
            }

            public function canRetry(ImportSession $session): bool
            {
                return true;
            }

            protected function executeDatabase(ImportSession $session, DatabaseSourceManifest $source): void
            {
                $this->ran = true;
            }
        };
        $schema->table('migration_direct_fixture', function (Blueprint $table): void {
            $table->string('changed');
        });
        expect(fn () => $manifest->assertUnchanged())->toThrow(RuntimeException::class, 'Source database schema changed');
        try {
            $executor->execute($session);
            throw new LogicException('Expected the source schema check to fail.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('RuntimeException: import_failed')
                ->and($exception->getPrevious())->toBeNull();
        }
        expect($executor->ran)->toBeFalse();
    } finally {
        $schema->dropIfExists('migration_direct_fixture');
    }
});

it('rejects invalid connection names, duplicate tables and credentials in a manifest', function (): void {
    $connection = DB::getDefaultConnection();
    expect(fn () => DatabaseSourceManifest::preview('missing-connection', ['table'], 'fixture'))->toThrow(InvalidArgumentException::class);
    expect(fn () => DatabaseSourceManifest::preview($connection, ['table', 'table'], 'fixture'))->toThrow(InvalidArgumentException::class);
    expect(fn () => DatabaseSourceManifest::preview($connection, ['unsafe.table'], 'fixture'))->toThrow(InvalidArgumentException::class);
    expect(fn () => DatabaseSourceManifest::fromArray(['type' => 'database', 'connection' => $connection, 'tables' => ['table'], 'schema_fingerprint' => str_repeat('a', 64), 'password' => 'secret']))->toThrow(InvalidArgumentException::class);
});

it('rejects missing manifests on direct database execution', function (): void {
    $executor = new FakeDatabaseImportExecutor;
    expect(fn () => DatabaseSourceManifest::fromArray([]))->toThrow(InvalidArgumentException::class, 'Invalid database source manifest');
    try {
        $executor->execute(new ImportSession);
        throw new LogicException('Expected a missing-manifest failure.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('InvalidArgumentException: import_failed')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('refuses an unguarded registered executor for a database manifest', function (): void {
    $registry = new ImportSessionExecutorRegistry;
    $registry->register(new RecordingImportSessionExecutor);
    $session = new ImportSession(['source_environment' => 'handoff-test', 'manifest' => ['type' => 'database']]);
    expect(fn () => $registry->executorFor($session))->toThrow(LogicException::class, 'guarded database executor');
});

it('lets a prepended executor take priority for an existing source', function (): void {
    $registry = new ImportSessionExecutorRegistry;
    $first = new RecordingImportSessionExecutor;
    $preferred = new RecordingImportSessionExecutor;
    $registry->register($first);
    $registry->register($preferred, prepend: true);
    $session = new ImportSession(['source_environment' => 'handoff-test']);
    expect($registry->executorFor($session))->toBe($preferred);
});
