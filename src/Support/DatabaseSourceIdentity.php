<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use WeakMap;

final class DatabaseSourceIdentity
{
    /** @var WeakMap<Connection, string>|null */
    private static ?WeakMap $memoryConnections = null;

    public static function forLabel(string $label): string
    {
        return hash('sha256', 'database-source-label:' . $label);
    }

    public static function forConnection(string $name, string $label = ''): string
    {
        $tuple = self::tuple($name);

        return hash('sha256', json_encode([...array_values($tuple), $label], JSON_THROW_ON_ERROR));
    }

    /** @return array{driver: string, host: string|null, port: string, database: string} */
    public static function tuple(string $name): array
    {
        $connection = DB::connection($name);
        $driver = $connection->getConfig('driver');
        $database = $connection->getConfig('database');
        $host = $connection->getConfig('host');
        $port = $connection->getConfig('port');
        if (! is_string($driver) || $driver === '' || ! is_string($database) || $database === ''
            || ($host !== null && ! is_string($host)) || ($port !== null && ! is_string($port) && ! is_int($port))) {
            throw new InvalidArgumentException('Invalid database source identity.');
        }
        if ($driver === 'sqlite') {
            if ($database === ':memory:') {
                self::$memoryConnections ??= new WeakMap;
                self::$memoryConnections[$connection] ??= bin2hex(random_bytes(32));
                $database = ':memory:' . self::$memoryConnections[$connection];
            } else {
                $resolved = realpath($database);
                if (! is_string($resolved)) {
                    throw new InvalidArgumentException('Database source file does not exist.');
                }
                $database = $resolved;
            }
        } elseif (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('Database source host is required.');
        }
        if (is_string($host)) {
            $host = strtolower($host);
        }
        $port = $port === null || $port === '' ? match ($driver) {
            'mysql', 'mariadb' => '3306',
            'pgsql' => '5432',
            default => '',
        } : (string) $port;

        return ['driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $database];
    }
}
