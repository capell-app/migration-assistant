<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Exceptions\MissingDatabaseSourceLabelException;
use Capell\MigrationAssistant\Support\DatabaseServerIdentity;
use Capell\MigrationAssistant\Support\DatabaseSourceIdentity;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseSourceManifest
{
    /** @param list<string> $tables */
    private function __construct(
        public string $connection,
        public array $tables,
        public string $schemaFingerprint,
        public string $sourceIdentity,
        public string $sourceLabel,
        public string $connectionIdentity,
        public ?DatabaseImportWritePlan $writePlan = null,
    ) {}

    /** @param list<string> $tables */
    public static function preview(string $connection, array $tables, string $sourceLabel, ?DatabaseImportWritePlan $writePlan = null): self
    {
        self::validate($connection, $tables, $sourceLabel, $writePlan);
        sort($tables, SORT_STRING);

        return new self($connection, $tables, self::fingerprint($connection, $tables), DatabaseSourceIdentity::forLabel($sourceLabel), $sourceLabel, DatabaseServerIdentity::forConnection($connection), $writePlan);
    }

    /** @param array<array-key, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        $legacyFingerprint = $manifest['schema_fingerprint'] ?? null;
        if (($manifest['type'] ?? null) === 'database' && ! array_key_exists('source_label', $manifest) && is_string($legacyFingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $legacyFingerprint) === 1 && array_diff(array_keys($manifest), ['type', 'connection', 'tables', 'schema_fingerprint', 'source_identity', 'connection_identity']) === []) {
            throw new MissingDatabaseSourceLabelException;
        }

        $connection = $manifest['connection'] ?? null;
        $tables = $manifest['tables'] ?? null;
        $fingerprint = $manifest['schema_fingerprint'] ?? null;
        $identity = $manifest['source_identity'] ?? null;
        $label = $manifest['source_label'] ?? null;
        $connectionIdentity = $manifest['connection_identity'] ?? null;
        $writePlan = array_key_exists('write_plan', $manifest) ? DatabaseImportWritePlan::fromArray(is_array($manifest['write_plan']) ? $manifest['write_plan'] : []) : null;
        $keys = array_keys($manifest);
        $expected = ['connection', 'connection_identity', 'schema_fingerprint', 'source_identity', 'source_label', 'tables', 'type'];
        if ($writePlan instanceof DatabaseImportWritePlan) {
            $expected[] = 'write_plan';
        }

        sort($keys);

        if ($keys !== $expected || ($manifest['type'] ?? null) !== 'database'
            || ! is_string($connection) || ! is_array($tables) || ! array_is_list($tables)
            || ! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || ! is_string($identity) || preg_match('/\A[a-f0-9]{64}\z/', $identity) !== 1 || ! is_string($label) || ! is_string($connectionIdentity) || preg_match('/\A[a-f0-9]{64}\z/', $connectionIdentity) !== 1) {
            throw new InvalidArgumentException('Invalid database source manifest.');
        }

        self::validate($connection, $tables, $label, $writePlan);
        sort($tables, SORT_STRING);

        return new self($connection, $tables, $fingerprint, $identity, $label, $connectionIdentity, $writePlan);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => 'database', 'connection' => $this->connection, 'tables' => $this->tables, 'schema_fingerprint' => $this->schemaFingerprint, 'source_identity' => $this->sourceIdentity, 'source_label' => $this->sourceLabel, 'connection_identity' => $this->connectionIdentity, ...($this->writePlan instanceof DatabaseImportWritePlan ? ['write_plan' => $this->writePlan->toArray()] : [])];
    }

    public function assertUnchanged(): void
    {
        $this->writePlan?->assertUnchanged();
        if (! hash_equals($this->sourceIdentity, DatabaseSourceIdentity::forLabel($this->sourceLabel)) || ! hash_equals($this->connectionIdentity, DatabaseServerIdentity::forConnection($this->connection))) {
            throw new RuntimeException('Source database identity changed since preview.');
        }

        $record = DB::connection($this->writePlan?->writerConnection)->table(TableName::resolve('database_source_labels'))->where('label', $this->sourceLabel)->first();
        if ($record !== null && ! DatabaseServerIdentity::tupleMatches($record->connection_tuple ?? null, $this->connection)) {
            throw new ImportRefusedException((string) __('migration-assistant::imports.source_label_moved', ['label' => $this->sourceLabel]));
        }

        if (! hash_equals($this->schemaFingerprint, self::fingerprint($this->connection, $this->tables))) {
            throw new RuntimeException('Source database schema changed since preview.');
        }
    }

    public function sourceKey(string $key): string
    {
        return $this->sourceIdentity . ':' . $key;
    }

    /** @param array<array-key, mixed> $tables */
    private static function validate(string $connection, array $tables, string $sourceLabel, ?DatabaseImportWritePlan $writePlan = null): void
    {
        if ($connection === '' || (! $writePlan instanceof DatabaseImportWritePlan && $connection === DB::getDefaultConnection()) || ! is_array(config('database.connections.' . $connection)) || $tables === [] || count(array_unique($tables, SORT_REGULAR)) !== count($tables) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $sourceLabel) !== 1 || strlen($sourceLabel) > 64) {
            throw new InvalidArgumentException('Invalid database source connection or tables.');
        }

        if (! $writePlan instanceof DatabaseImportWritePlan && hash_equals(DatabaseSourceIdentity::forConnection($connection), DatabaseSourceIdentity::forConnection(DB::getDefaultConnection()))) {
            throw new InvalidArgumentException('Source database matches the destination.');
        }

        if (! $writePlan instanceof DatabaseImportWritePlan && hash_equals(DatabaseServerIdentity::forConnection($connection), DatabaseServerIdentity::forConnection(DB::getDefaultConnection()))) {
            throw new InvalidArgumentException('Source database matches the destination.');
        }

        $validatedTables = [];
        foreach ($tables as $table) {
            if (! is_string($table) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
                throw new InvalidArgumentException('Invalid database source table name.');
            }

            $validatedTables[] = $table;
        }

        if ($writePlan instanceof DatabaseImportWritePlan && ($writePlan->readerConnection !== $connection || ($writePlan->executionMode !== 'dedicated-process' && $tables !== ['settings']) || ($writePlan->executionMode === 'dedicated-process' && array_diff(array_map(static fn (string $table): string => DB::connection($connection)->getTablePrefix() . $table, $validatedTables), $writePlan->sourceTables) !== []))) {
            throw new ImportRefusedException('The in-place source is outside its write plan.');
        }

    }

    /** @param list<string> $tables */
    private static function fingerprint(string $connection, array $tables): string
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        $definitions = [];
        foreach ($tables as $table) {
            if (! $schema->hasTable($table)) {
                throw new InvalidArgumentException('Database source table does not exist: ' . $table);
            }

            $columns = $schema->getColumns($table);
            usort($columns, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            foreach ($columns as &$column) {
                ksort($column);
            }

            unset($column);
            $definitions[$table] = $columns;
        }

        return hash('sha256', json_encode($definitions, JSON_THROW_ON_ERROR));
    }
}
