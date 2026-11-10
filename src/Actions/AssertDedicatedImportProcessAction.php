<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Illuminate\Support\Facades\DB;
use PDO;

/** Verifies an already restricted CLI bootstrap; never changes its default or connection configuration. */
final class AssertDedicatedImportProcessAction
{
    private ?string $writer = null;

    private ?PDO $pdo = null;

    private ?string $configurationDigest = null;

    public function handle(string $inspector, string $reader, string $writer): void
    {
        $profiles = config('database.connections');
        $names = is_array($profiles) ? array_keys($profiles) : [];
        sort($names);
        $expected = [$inspector, $reader, $writer];
        sort($expected);
        if (! app()->runningInConsole() || count(array_unique($expected)) !== 3 || $names !== $expected || array_diff(array_keys(DB::getConnections()), $expected) !== [] || DB::getDefaultConnection() !== $writer || config('queue.default') !== 'sync' || config('cache.default') !== 'array' || config('session.driver') !== 'array') {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('Dedicated imports require a restricted writer as the bootstrapped default, only three planned profiles, and synchronous queue/array cache/session stores.');
        }

        foreach (['DB_USERNAME' => 'username', 'DB_PASSWORD' => 'password'] as $variable => $key) {
            $ambient = getenv($variable);
            $configured = DB::connection($writer)->getConfig($key);
            if (is_string($ambient) && $ambient !== '' && (! is_string($configured) || $ambient !== $configured)) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The dedicated process environment contains another database credential.');
            }
        }

        foreach (['DB_URL', 'DATABASE_URL', 'DB_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MARIADB_ROOT_PASSWORD'] as $variable) {
            $ambient = getenv($variable);
            if (is_string($ambient) && $ambient !== '') {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The dedicated process environment contains an ambient database URL or administrative credential.');
            }
        }

        resolve(AssertInPlaceDatabaseIdentityAction::class)->assertNames($inspector, $reader, $writer);
        $pdo = DB::connection($writer)->getPdo();
        $digest = hash('sha256', json_encode([$profiles, config('database.default'), config('queue'), config('cache'), config('session')], JSON_THROW_ON_ERROR));
        if ($this->writer === null) {
            $this->writer = $writer;
            $this->pdo = $pdo;
            $this->configurationDigest = $digest;
            app()->terminating(fn () => $this->assertPinned($inspector, $reader, $writer));
        }

        if ($this->writer !== $writer || $this->pdo !== $pdo || ! hash_equals((string) $this->configurationDigest, $digest)) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The dedicated import default, PDO or connection configuration changed.');
        }
    }

    private function assertPinned(string $inspector, string $reader, string $writer): void
    {
        $this->handle($inspector, $reader, $writer);
        $connection = DB::connection($writer);
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The dedicated import left an unfinished transaction at termination.');
        }
    }
}
