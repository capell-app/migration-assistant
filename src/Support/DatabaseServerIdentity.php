<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use Throwable;
use WeakMap;

final class DatabaseServerIdentity
{
    /** @var WeakMap<PDO, string>|null */
    private static ?WeakMap $memoryDatabases = null;

    /**
     * Reads the identity of a MySQL-protocol server through $select, which runs one statement and returns its
     * first row. MySQL reports a stable `@@server_uuid`. MariaDB has none, so its server id and port identify the
     * instance together with the database name. The host name is deliberately not used: in a container it is the
     * container id and changes whenever the container is recreated.
     *
     * @param  Closure(string): mixed  $select
     * @return array{0: string, 1: mixed, 2: mixed} the identity family, the server marker and the database name
     */
    public static function mysqlIdentity(Closure $select): array
    {
        $version = data_get($select('SELECT VERSION() AS version'), 'version');
        if (is_string($version) && str_contains(strtolower($version), 'mariadb')) {
            $row = $select('SELECT @@server_id AS server_id, @@port AS server_port, DATABASE() AS database_name');
            $id = data_get($row, 'server_id');
            $port = data_get($row, 'server_port');
            if ((! is_string($id) && ! is_int($id)) || (! is_string($port) && ! is_int($port))) {
                throw new InvalidArgumentException('The database server identity is unavailable.');
            }

            return ['mariadb', $id . ':' . $port, data_get($row, 'database_name')];
        }
        $row = $select('SELECT @@server_uuid AS server_id, DATABASE() AS database_name');

        return ['mysql', data_get($row, 'server_id'), data_get($row, 'database_name')];
    }

    /**
     * Reads the identity of a PostgreSQL server through $select, which runs one statement and returns its first row.
     * `pg_control_system()` is the only stable cluster identifier PostgreSQL exposes, and by default only a superuser
     * may call it. A role without EXECUTE on it is refused with the remedy, rather than failing with a bare
     * permission error that says nothing about which privilege is missing.
     *
     * @param  Closure(string): mixed  $select
     * @return array{0: string, 1: mixed, 2: mixed} the identity family, the server marker and the database name
     */
    public static function pgsqlIdentity(Closure $select): array
    {
        $privilege = data_get($select("SELECT has_function_privilege(current_user, 'pg_control_system()', 'EXECUTE') AS allowed"), 'allowed');
        if ($privilege !== true && $privilege !== 1 && $privilege !== 't' && $privilege !== '1') {
            throw new ImportRefusedException((string) __('migration-assistant::imports.pgsql_identity_privilege'));
        }
        $row = $select('SELECT system_identifier::text AS server_id, current_database() AS database_name FROM pg_control_system()');

        return ['pgsql', data_get($row, 'server_id'), data_get($row, 'database_name')];
    }

    /** Whether the label's stored connection tuple (driver, host, port, database) still matches the live configuration. */
    public static function tupleMatches(mixed $stored, string $connection): bool
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) && $decoded === DatabaseSourceIdentity::tuple($connection);
    }

    public static function forConnection(string $name): string
    {
        $connection = DB::connection($name);
        $driver = $connection->getDriverName();
        try {
            if ($driver === 'sqlite') {
                $row = $connection->selectOne('PRAGMA database_list');
                $file = data_get($row, 'file');
                if (! is_string($file)) {
                    throw new InvalidArgumentException('Unable to identify the SQLite database.');
                }
                if ($file === '') {
                    $pdo = $connection->getPdo();
                    self::$memoryDatabases ??= new WeakMap;
                    self::$memoryDatabases[$pdo] ??= bin2hex(random_bytes(32));
                    $file = ':memory:' . self::$memoryDatabases[$pdo];
                } else {
                    $file = realpath($file);
                    if (! is_string($file)) {
                        throw new InvalidArgumentException('Unable to identify the SQLite database.');
                    }
                }

                return hash('sha256', 'sqlite:' . $file);
            }
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                [$family, $server, $database] = self::mysqlIdentity(static fn (string $sql): mixed => $connection->selectOne($sql));
            } elseif ($driver === 'pgsql') {
                [$family, $server, $database] = self::pgsqlIdentity(static fn (string $sql): mixed => $connection->selectOne($sql));
            } else {
                throw new InvalidArgumentException('The database driver cannot report a safe server identity.');
            }
            if ((! is_string($server) && ! is_int($server)) || ! is_string($database) || $database === '') {
                throw new InvalidArgumentException('The database server identity is unavailable.');
            }

            return hash('sha256', json_encode([$family, $server, $database], JSON_THROW_ON_ERROR));
        } catch (ImportRefusedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidArgumentException('The database server identity could not be verified.');
        }
    }
}
