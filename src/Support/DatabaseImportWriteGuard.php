<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Actions\AssertDedicatedImportProcessAction;
use Capell\MigrationAssistant\Actions\AssertInPlaceDatabaseIdentityAction;
use Capell\MigrationAssistant\Data\DatabaseImportWritePlan;
use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DatabaseImportWriteGuard
{
    /** @var array<string, DatabaseImportWritePlan> */
    private static array $active = [];

    /** @var array<string, array<string, Closure(): void>> */
    private static array $pending = [];

    public static function isDedicated(): bool
    {
        return array_any(self::$active, fn (DatabaseImportWritePlan $plan): bool => $plan->executionMode === 'dedicated-process');
    }

    /** Collect built-in finalisation inside the verified transaction instead of application termination. */
    public static function deferWithin(string $key, Closure $callback): bool
    {
        foreach (self::$active as $digest => $plan) {
            if ($plan->executionMode === 'dedicated-process') {
                self::$pending[$digest][$key] = $callback;

                return true;
            }
        }

        return false;
    }

    public static function fresh(string $reader, string $writer, bool $dedicatedProcess = false): void
    {
        if ($reader === $writer || $reader === DB::getDefaultConnection() || (! $dedicatedProcess && $writer === DB::getDefaultConnection())) {
            throw InPlaceDatabaseRefusalReason::Unsupported->exception('In-place imports require distinct reader and writer profiles separate from the app default.');
        }

        // Inspect BOTH profiles first. Purging the first must never discard the second's active transaction.
        foreach ([$reader, $writer] as $name) {
            $connection = DB::connection($name);
            if ($connection->getName() !== $name) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('A resolved database connection name does not match its requested profile key.');
            }

            if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                throw InPlaceDatabaseRefusalReason::Unsupported->exception('In-place profiles must not have an existing Laravel or PDO transaction.');
            }
        }

        foreach ([$reader, $writer] as $name) {
            if (! $dedicatedProcess || $name !== $writer) {
                DB::purge($name);
            }
        }
    }

    public static function qualifiedTable(Connection $connection, string $physicalTable): string
    {
        // Both segments are already physical identifiers. Never prepend the connection prefix again.
        return $connection->getQueryGrammar()->wrapTable($connection->getDatabaseName() . '.' . $physicalTable, '');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function handle(DatabaseImportWritePlan $plan, Closure $callback): mixed
    {
        $digest = $plan->digest();
        if (isset(self::$active[$digest])) {
            $plan->assertUnchanged();

            return $callback();
        }

        if ($plan->executionMode === 'dedicated-process') {
            app()->singletonIf(AssertDedicatedImportProcessAction::class);
            resolve(AssertDedicatedImportProcessAction::class)->handle($plan->logicalConnection, $plan->readerConnection, $plan->writerConnection);
        }

        resolve(AssertInPlaceDatabaseIdentityAction::class)->assertNames($plan->logicalConnection, $plan->readerConnection, $plan->writerConnection);
        resolve(AssertInPlaceDatabaseIdentityAction::class)->assertEndpoints(DB::connection($plan->logicalConnection), DB::connection($plan->readerConnection), DB::connection($plan->writerConnection));
        self::fresh($plan->readerConnection, $plan->writerConnection, $plan->executionMode === 'dedicated-process');
        $plan->assertUnchanged();
        $reader = DB::connection($plan->readerConnection);
        $writer = DB::connection($plan->writerConnection);

        return $reader->transaction(function () use ($plan, $callback, $digest, $reader, $writer): mixed {
            return $writer->transaction(function () use ($plan, $callback, $digest, $reader, $writer): mixed {
                $tables = $plan->readTables;
                sort($tables, SORT_STRING);
                foreach ($tables as $table) {
                    // A real table read holds MariaDB metadata locks through transaction end, including empty tables.
                    $reader->select('SELECT 1 FROM ' . self::qualifiedTable($reader, $table) . ' LIMIT 1');
                }

                foreach (array_merge(array_values($plan->targets), array_values(array_diff_key($plan->storage, ['database_source_label_locks' => true]))) as $table) {
                    $writer->select('SELECT 1 FROM ' . self::qualifiedTable($writer, $table) . ' LIMIT 1 FOR UPDATE');
                }

                $plan->assertUnchanged();
                self::$active[$digest] = $plan;
                try {
                    // Built-in settings/provenance writes must not dispatch arbitrary host observers.
                    return Model::withoutEvents(function () use ($callback, $digest): mixed {
                        $result = $callback();
                        while ((self::$pending[$digest] ?? []) !== []) {
                            $pending = self::$pending[$digest];
                            unset(self::$pending[$digest]);
                            foreach ($pending as $finalise) {
                                $finalise();
                            }
                        }

                        return $result;
                    });
                } finally {
                    unset(self::$active[$digest], self::$pending[$digest]);
                }
            });
        });
    }
}
