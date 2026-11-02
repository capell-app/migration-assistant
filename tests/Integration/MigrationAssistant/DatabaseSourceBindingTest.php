<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\AssertDatabaseSourceLabelBoundAction;
use Capell\MigrationAssistant\Actions\RegisterDatabaseSourceLabelAction;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Support\DatabaseServerIdentity;
use Capell\MigrationAssistant\Support\SafeFailureCause;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * A stand-in server: answers whichever `@@variables` the probe asks for, so the test proves behaviour (what the
 * identity depends on) rather than the wording of the SQL.
 *
 * @param  array<string, mixed>  $server
 */
function fakeMysqlServer(array $server): Closure
{
    return static function (string $sql) use ($server): object {
        if (str_contains($sql, 'VERSION()')) {
            return (object) ['version' => $server['version']];
        }
        preg_match_all('/(@@\w+|DATABASE\(\))\s+AS\s+(\w+)/', $sql, $matches, PREG_SET_ORDER);
        $row = [];
        foreach ($matches as [, $expression, $alias]) {
            $row[$alias] = $expression === 'DATABASE()' ? $server['database'] : ($server[ltrim($expression, '@')] ?? null);
        }

        return (object) $row;
    };
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mariadbServer(array $overrides = []): array
{
    return ['version' => '10.11.6-MariaDB-1:10.11.6+maria~ubu2204', 'server_id' => 1, 'port' => 3306, 'hostname' => 'container-aaa', 'database' => 'app', ...$overrides];
}

it('keeps a MariaDB identity stable when only the container host name changes', function (): void {
    $before = DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer()));
    $recreated = DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer(['hostname' => 'container-bbb'])));

    expect($recreated)->toBe($before)->and($before[0])->toBe('mariadb');
});

it('changes a MariaDB identity when the server id, the port or the database changes', function (): void {
    $base = DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer()));

    expect(DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer(['server_id' => 2]))))->not->toBe($base)
        ->and(DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer(['port' => 3307]))))->not->toBe($base)
        ->and(DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(mariadbServer(['database' => 'other']))))->not->toBe($base);
});

it('identifies a MySQL server by its server uuid and ignores its host name and server id', function (): void {
    $server = ['version' => '8.0.36', 'server_uuid' => 'uuid-one', 'server_id' => 1, 'hostname' => 'host-a', 'database' => 'app'];
    $base = DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer($server));

    expect($base[0])->toBe('mysql')
        ->and(DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(array_replace($server, ['hostname' => 'host-b', 'server_id' => 9]))))->toBe($base)
        ->and(DatabaseServerIdentity::mysqlIdentity(fakeMysqlServer(array_replace($server, ['server_uuid' => 'uuid-two']))))->not->toBe($base);
});

it('refuses a repoint to another host or database even when the server identity is unchanged', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tuple');
    config(['database.connections.tuple_fixture' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
    $register = resolve(RegisterDatabaseSourceLabelAction::class);
    $register->handle('tuple-label', 'tuple_fixture', false, false);
    $register->handle('tuple-label', 'tuple_fixture', false, false);

    // Same server identity, but the stored host, port and database tuple now differs (staging repointed to production).
    DB::table('database_source_labels')->where('label', 'tuple-label')->update(['connection_tuple' => json_encode(['driver' => 'sqlite', 'host' => 'staging.example.test', 'port' => '', 'database' => $file], JSON_THROW_ON_ERROR)]);

    expect(DatabaseServerIdentity::tupleMatches(DB::table('database_source_labels')->where('label', 'tuple-label')->value('connection_tuple'), 'tuple_fixture'))->toBeFalse()
        ->and(fn () => $register->handle('tuple-label', 'tuple_fixture', false, false))->toThrow(LogicException::class, 'bound to a different database server');
    $register->handle('tuple-label', 'tuple_fixture', true, false);
    expect(DatabaseServerIdentity::tupleMatches(DB::table('database_source_labels')->where('label', 'tuple-label')->value('connection_tuple'), 'tuple_fixture'))->toBeTrue();
    $register->handle('tuple-label', 'tuple_fixture', false, false);
    DB::purge('tuple_fixture');
    unlink($file);
});

it('binds a source label to the server behind the connection, not to its configuration', function (): void {
    config(['database.connections.label_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $register = resolve(RegisterDatabaseSourceLabelAction::class);
    $register->handle('server-label', 'label_fixture', false, false);
    $register->handle('server-label', 'label_fixture', false, false);
    $first = DatabaseServerIdentity::forConnection('label_fixture');

    DB::purge('label_fixture');

    expect(DatabaseServerIdentity::forConnection('label_fixture'))->not->toBe($first)
        ->and(fn () => $register->handle('server-label', 'label_fixture', false, false))->toThrow(LogicException::class, 'bound to a different database server');
    $register->handle('server-label', 'label_fixture', true, false);
    expect(DB::table('database_source_labels')->where('label', 'server-label')->value('connection_identity'))->toBe(DatabaseServerIdentity::forConnection('label_fixture'));
});

it('records the server identity in the source manifest', function (): void {
    config(['database.connections.manifest_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::connection('manifest_fixture')->getSchemaBuilder()->create('manifest_fixture_rows', static function (Blueprint $table): void {
        $table->id();
    });

    $manifest = DatabaseSourceManifest::preview('manifest_fixture', ['manifest_fixture_rows'], 'manifest-label');

    expect($manifest->connectionIdentity)->toBe(DatabaseServerIdentity::forConnection('manifest_fixture'));
    DB::purge('manifest_fixture');
    expect(fn () => $manifest->assertUnchanged())->toThrow(RuntimeException::class, 'identity changed');
});

it('describes a query failure by SQLSTATE and driver code only, never by message text', function (): void {
    $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'o\'brien@x' for key 'users.users_email_unique'");
    $driver->errorInfo = ['23000', 1062, "Duplicate entry 'o\'brien@x'"];
    $query = new QueryException('mysql', 'insert into users (email) values (?)', ["o'brien@x"], $driver);

    $description = SafeFailureCause::describe($query);

    expect($description)->toBe(QueryException::class . ': SQLSTATE 23000, driver code 1062')
        ->and($description)->not->toContain("o'brien", 'users_email_unique');
});

it('names an exception that is not on the allow-list by class alone', function (Throwable $exception): void {
    expect(SafeFailureCause::describe($exception))->toBe($exception::class);
})->with([
    'carbon parse error' => function (): Throwable {
        try {
            Carbon\Carbon::parse("o'brien@x is not a date");
        } catch (Throwable $exception) {
            return $exception;
        }

        return new LogicException('Carbon accepted the value.');
    },
    'invalid argument' => fn (): Throwable => new InvalidArgumentException("Bad value o'brien@x"),
    'plain logic exception' => fn (): Throwable => new LogicException("secret o'brien@x"),
    'runtime exception' => fn (): Throwable => new RuntimeException('provider-secret-token'),
]);

it('shows the fixed text of an allow-listed refusal', function (): void {
    $refusal = new ImportRefusedException('The source label "x" is bound to a different database server.');

    expect(SafeFailureCause::describe($refusal))->toBe(ImportRefusedException::class . ': The source label "x" is bound to a different database server.');
});

it('refuses a prepared session when the label keeps its identity hash but its connection tuple differs', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'tuplebound');
    config(['database.connections.tuple_bound' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
    resolve(RegisterDatabaseSourceLabelAction::class)->handle('tuple-bound', 'tuple_bound', false, false);
    DB::connection('tuple_bound')->getSchemaBuilder()->create('tuple_bound_rows', static function (Blueprint $table): void {
        $table->id();
    });
    $manifest = DatabaseSourceManifest::preview('tuple_bound', ['tuple_bound_rows'], 'tuple-bound');
    $bound = resolve(AssertDatabaseSourceLabelBoundAction::class);
    $bound->handle($manifest);

    DB::table('database_source_labels')->where('label', 'tuple-bound')->update(['connection_tuple' => json_encode(['driver' => 'sqlite', 'host' => 'other.example.test', 'port' => '', 'database' => $file], JSON_THROW_ON_ERROR)]);

    expect(fn () => $bound->handle($manifest))->toThrow(ImportRefusedException::class, 'repointed');
    DB::purge('tuple_bound');
    unlink($file);
});

/**
 * A stand-in PostgreSQL server: the role either may or may not execute pg_control_system().
 */
function fakePgsqlServer(bool $superuser): Closure
{
    return static function (string $sql) use ($superuser): object {
        if (str_contains($sql, 'has_function_privilege')) {
            return (object) ['allowed' => $superuser];
        }
        if (! $superuser) {
            throw new RuntimeException('permission denied for function pg_control_system');
        }

        return (object) ['server_id' => '7301234567890123456', 'database_name' => 'app'];
    };
}

it('reads a PostgreSQL identity for a role that may execute pg_control_system', function (): void {
    expect(DatabaseServerIdentity::pgsqlIdentity(fakePgsqlServer(true)))->toBe(['pgsql', '7301234567890123456', 'app']);
});

it('refuses a PostgreSQL identity for a non-superuser role and names the missing privilege', function (): void {
    expect(fn () => DatabaseServerIdentity::pgsqlIdentity(fakePgsqlServer(false)))
        ->toThrow(ImportRefusedException::class, 'pg_control_system()');
});
