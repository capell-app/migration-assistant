<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Capell\MigrationAssistant\Support\DatabaseSessionLockChallenge;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

final class AssertInPlaceDatabaseIdentityAction
{
    public function assertNames(string $logical, string $reader, string $writer): void
    {
        foreach ([$logical, $reader, $writer] as $name) {
            if (DB::connection($name)->getName() !== $name) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('A resolved database connection name does not match its requested profile key.');
            }
        }
    }

    /** @return array{PDO, PDO, PDO} */
    public function handle(Connection $logical, Connection $reader, Connection $writer): array
    {
        $endpoint = $this->assertEndpoints($logical, $reader, $writer);
        $handles = [$logical->getPdo(), $reader->getPdo(), $writer->getPdo()];
        if ($handles[0] === $handles[1] || $handles[0] === $handles[2] || $handles[1] === $handles[2]) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('Distinct physical PDO sessions are required.');
        }

        foreach ($handles as $pdo) {
            $database = $this->scalar($pdo)('SELECT DATABASE()', []);
            if ($database !== $endpoint['database']) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('An actual PDO session selected another database.');
            }
        }

        resolve(DatabaseSessionLockChallenge::class)->handle($this->scalar($handles[0]), $this->scalar($handles[1]), $this->scalar($handles[2]));

        return $handles;
    }

    /** @return array{host: string, port: int, database: string} */
    public function assertEndpoints(Connection $logical, Connection $reader, Connection $writer): array
    {
        $endpoint = $this->endpoint($logical);
        foreach ([$reader, $writer] as $connection) {
            if ($this->endpoint($connection) !== $endpoint) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The database endpoint and schema must match across the three profiles.');
            }
        }

        return $endpoint;
    }

    /** @param array{PDO, PDO, PDO} $handles */
    public function assertPinned(array $handles, Connection $logical, Connection $reader, Connection $writer): void
    {
        if ($handles !== [$logical->getPdo(), $reader->getPdo(), $writer->getPdo()]) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('A physical PDO session changed during the write proof.');
        }
    }

    /** @return array{host: string, port: int, database: string} */
    private function endpoint(Connection $connection): array
    {
        $host = $connection->getConfig('host');
        $port = $connection->getConfig('port') ?: 3306;
        $database = $connection->getConfig('database');
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || ! is_string($host) || preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $host) !== 1 || (! is_int($port) && ! is_string($port)) || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false || ! is_string($database) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $database) !== 1 || ! in_array($connection->getConfig('unix_socket'), [null, ''], true) || ! in_array($connection->getConfig('url'), [null, ''], true)) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('In-place database identity requires explicit matching TCP endpoints without socket or URL DSNs.');
        }

        return ['host' => strtolower($host), 'port' => (int) $port, 'database' => $database];
    }

    /** @return Closure(string, list<mixed>): mixed */
    private function scalar(PDO $pdo): Closure
    {
        // Use these exact handles, with no Laravel reconnect/retry or alternate read PDO.
        return static function (string $sql, array $bindings) use ($pdo): mixed {
            $statement = $pdo->prepare($sql);
            if ($statement === false) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The physical PDO challenge could not be prepared.');
            }

            $statement->execute($bindings);

            return $statement->fetchColumn();
        };
    }
}
