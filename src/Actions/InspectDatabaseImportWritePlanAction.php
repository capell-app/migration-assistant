<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Data\DatabaseImportWritePlan;
use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Support\DatabaseServerIdentity;
use Capell\MigrationAssistant\Support\DatabaseSourceIdentity;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PDO;

final class InspectDatabaseImportWritePlanAction
{
    /**
     * @param  list<class-string<Model>>  $classes
     * @param  list<string>  $configurationKeys
     * @param  list<string>|null  $physicalSourceTables
     */
    public function handle(string $logical, string $readerName, string $writerName, array $classes, array $configurationKeys, string $executionMode = 'explicit-profiles', ?array $physicalSourceTables = null): DatabaseImportWritePlan
    {
        $process = $executionMode === 'dedicated-process';
        if ($process) {
            app()->singletonIf(AssertDedicatedImportProcessAction::class);
            resolve(AssertDedicatedImportProcessAction::class)->handle($logical, $readerName, $writerName);
        }

        if ($readerName === $writerName || (! $process && in_array(DB::getDefaultConnection(), [$readerName, $writerName], true)) || $classes === [] || $configurationKeys === []) {
            throw new ImportRefusedException('Distinct restricted profiles and an explicit in-place target plan are required.');
        }

        resolve(AssertInPlaceDatabaseIdentityAction::class)->assertNames($logical, $readerName, $writerName);
        $inspector = DB::connection($logical);
        $reader = DB::connection($readerName);
        $writer = DB::connection($writerName);
        $physicalIdentity = resolve(AssertInPlaceDatabaseIdentityAction::class);
        $handles = $physicalIdentity->handle($inspector, $reader, $writer);
        foreach ([$inspector, $reader, $writer] as $connection) {
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                throw InPlaceDatabaseRefusalReason::Unsupported->exception('In-place settings currently require MariaDB 10.11.');
            }

            $version = data_get($connection->selectOne('SELECT VERSION() AS version'), 'version');
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || ! is_string($version) || preg_match('/\A10\.11\.[0-9]+-MariaDB/', $version) !== 1 || $connection->getConfig('read') !== null || $connection->getConfig('write') !== null || (bool) data_get($connection->getConfig('options'), (string) PDO::ATTR_PERSISTENT, false)) {
                throw new ImportRefusedException('In-place settings require MariaDB 10.11 without split or persistent connections.');
            }
        }

        if ($reader->getPdo() === $writer->getPdo() || $reader->getPdo() === $inspector->getPdo() || $writer->getPdo() === $inspector->getPdo()) {
            throw new ImportRefusedException('In-place data and metadata profiles require distinct PDO handles.');
        }

        $identity = DatabaseServerIdentity::forConnection($logical);
        foreach ([$readerName, $writerName] as $name) {
            if (! hash_equals($identity, DatabaseServerIdentity::forConnection($name))) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The restricted profiles do not point at the logical source database.');
            }
        }

        $inspectorPrincipal = $this->principal($inspector);
        $inspectorGrantee = $inspectorPrincipal['grantee'];
        // Global visibility is required to discover incoming cross-schema FK cascades too. No data rows use this connection.
        if ($inspector->selectOne("SELECT 1 AS allowed FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'SELECT'", [$inspectorGrantee]) === null) {
            throw InPlaceDatabaseRefusalReason::Visibility->exception('Complete global metadata visibility is required on the logical source profile.');
        }

        if ($process) {
            $this->assertReadOnlyInspector($inspector, $reader);
        }

        $targets = [];
        foreach ($classes as $class) {
            if (! is_subclass_of($class, Model::class)) {
                throw new ImportRefusedException('Invalid in-place target model.');
            }

            $model = new $class;
            if ($model->getConnectionName() !== null && $model->getConnectionName() !== $writerName) {
                throw new ImportRefusedException('A configured target uses another database connection.');
            }

            $targets[$class] = $this->identifier($writer->getTablePrefix() . $model->getTable());
        }

        ksort($targets);
        $storage = [];
        foreach (['import_sessions', 'import_source_keys', 'database_source_labels', 'import_rollback_reports', 'import_rollback_audits', 'database_source_label_locks'] as $table) {
            $storage[$table] = $this->identifier($writer->getTablePrefix() . TableName::resolve($table));
        }

        ksort($storage);
        $writeTables = [...array_values($targets), ...array_values(array_diff_key($storage, ['database_source_label_locks' => true]))];
        $sourceTables = $physicalSourceTables ?? [$this->identifier($reader->getTablePrefix() . 'settings')];
        $sourceTables = array_map($this->identifier(...), $sourceTables);
        sort($sourceTables, SORT_STRING);
        if (count(array_unique([...array_values($targets), ...array_values($storage)])) !== count($targets) + count($storage) || array_intersect(array_unique([...$sourceTables, $this->identifier($reader->getTablePrefix() . 'users')]), [...$writeTables, $storage['database_source_label_locks']]) !== []) {
            throw InPlaceDatabaseRefusalReason::Overlap->exception('The in-place physical source and destination tables overlap.');
        }

        $readerProof = $this->privileges($reader, [], true);
        $writerProof = $this->privileges($writer, $writeTables, false);
        if ($readerProof['principal'] === $writerProof['principal']) {
            throw new ImportRefusedException('Reader and writer must be different database accounts.');
        }

        foreach ([$readerProof, $writerProof] as $proof) {
            [$user, $host] = $proof['account'];
            foreach (['procs_priv', 'proxies_priv', 'roles_mapping'] as $table) {
                if ($reader->selectOne('SELECT 1 AS present FROM mysql.' . $table . ' WHERE User = ? AND Host = ? LIMIT 1', [$user, $host]) !== null) {
                    throw InPlaceDatabaseRefusalReason::Privilege->exception('In-place accounts must not have routine, proxy or role grants.');
                }
            }
        }

        if ($reader->selectOne('SELECT 1 AS present FROM mysql.func LIMIT 1') !== null) {
            throw InPlaceDatabaseRefusalReason::Privilege->exception('Registered native database functions prevent an in-place privilege proof.');
        }

        $readTables = array_values(array_unique([...$readerProof['tables'], ...$writerProof['tables']]));
        sort($readTables, SORT_STRING);
        foreach (array_unique([...$readTables, ...$sourceTables, ...$writeTables, $storage['database_source_label_locks']]) as $table) {
            $definition = $inspector->selectOne('SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$writer->getDatabaseName(), $table]);
            if ($definition === null || data_get($definition, 'TABLE_TYPE') !== 'BASE TABLE' || data_get($definition, 'ENGINE') !== 'InnoDB' || ! in_array($table, $readerProof['tables'], true)) {
                throw InPlaceDatabaseRefusalReason::Visibility->exception('Every planned application table must be an InnoDB base table readable by the restricted reader.');
            }
        }

        $triggers = $inspector->select('SELECT EVENT_OBJECT_TABLE, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME', [$writer->getDatabaseName()]);
        foreach ($triggers as $trigger) {
            if (in_array($trigger->EVENT_OBJECT_TABLE, $writeTables, true)) {
                throw InPlaceDatabaseRefusalReason::Trigger->exception('Destination triggers prevent an in-place write proof.');
            }
        }

        $foreignKeys = $inspector->select('SELECT CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, UNIQUE_CONSTRAINT_SCHEMA, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA = ? ORDER BY CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME', [$writer->getDatabaseName()]);
        foreach ($foreignKeys as $foreignKey) {
            if (in_array($foreignKey->REFERENCED_TABLE_NAME, $writeTables, true) && ($foreignKey->CONSTRAINT_SCHEMA !== $writer->getDatabaseName() || ! in_array($foreignKey->TABLE_NAME, $writeTables, true)) && (in_array($foreignKey->UPDATE_RULE, ['CASCADE', 'SET NULL'], true) || in_array($foreignKey->DELETE_RULE, ['CASCADE', 'SET NULL'], true))) {
                throw InPlaceDatabaseRefusalReason::Cascade->exception('A destination foreign key can cascade into a protected table.');
            }
        }

        $columns = [];
        foreach ($readTables as $table) {
            $columns[$table] = $inspector->select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$writer->getDatabaseName(), $table]);
        }

        sort($configurationKeys, SORT_STRING);
        $configuration = [];
        foreach ($configurationKeys as $key) {
            $configuration[$key] = config($key);
        }

        // Raw account and grant rows stay ephemeral. Only this digest is persisted or displayed.
        unset($readerProof['account'], $writerProof['account']);
        $digest = hash('sha256', json_encode([$identity, $inspectorPrincipal['digest'], DatabaseSourceIdentity::tuple($logical), DatabaseSourceIdentity::tuple($readerName), DatabaseSourceIdentity::tuple($writerName), $reader->getTablePrefix(), $writer->getTablePrefix(), $configuration, $readerProof, $writerProof, $columns, $foreignKeys, $triggers], JSON_THROW_ON_ERROR));

        $physicalIdentity->assertPinned($handles, $inspector, $reader, $writer);

        return new DatabaseImportWritePlan($logical, $readerName, $writerName, $sourceTables, $targets, $storage, $readTables, $configurationKeys, $digest, $executionMode);
    }

    private function assertReadOnlyInspector(Connection $inspector, Connection $reader): void
    {
        $principal = $this->principal($inspector);
        if ($principal['digest'] === $this->principal($reader)['digest'] || $inspector->select('SHOW GRANTS FOR PUBLIC') !== [] || $inspector->select('SELECT ROLE_NAME FROM information_schema.APPLICABLE_ROLES') !== []) {
            throw InPlaceDatabaseRefusalReason::Privilege->exception('The metadata inspector requires a distinct read-only account without PUBLIC or role grants.');
        }

        foreach (['USER_PRIVILEGES', 'SCHEMA_PRIVILEGES', 'TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES'] as $catalog) {
            foreach ($inspector->select('SELECT PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.' . $catalog . ' WHERE GRANTEE = ?', [$principal['grantee']]) as $grant) {
                if (! in_array($grant->PRIVILEGE_TYPE, ['USAGE', 'SELECT'], true) || $grant->IS_GRANTABLE !== 'NO') {
                    throw InPlaceDatabaseRefusalReason::Privilege->exception('The metadata inspector must have SELECT privileges only without grant options.');
                }
            }
        }

        [$user, $host] = $principal['account'];
        foreach (['procs_priv', 'proxies_priv', 'roles_mapping'] as $table) {
            if ($reader->selectOne('SELECT 1 AS present FROM mysql.' . $table . ' WHERE User = ? AND Host = ? LIMIT 1', [$user, $host]) !== null) {
                throw InPlaceDatabaseRefusalReason::Privilege->exception('The metadata inspector must not have routine, proxy or role grants.');
            }
        }
    }

    /** @return array{account: array{string, string}, grantee: string, digest: string} */
    private function principal(Connection $connection): array
    {
        $account = data_get($connection->selectOne('SELECT CURRENT_USER() AS account'), 'account');
        if (! is_string($account) || preg_match('/\A([A-Za-z0-9_]+)@([A-Za-z0-9_.:%-]+)\z/D', $account, $matches) !== 1) {
            throw new ImportRefusedException('Unsupported database account identity.');
        }

        return ['account' => [$matches[1], $matches[2]], 'grantee' => "'" . $matches[1] . "'@'" . $matches[2] . "'", 'digest' => hash('sha256', $account)];
    }

    /**
     * @param  list<string>  $writeTables
     * @return array{principal: string, account: array{string, string}, tables: list<string>, grants: list<array<string, mixed>>}
     */
    private function privileges(Connection $connection, array $writeTables, bool $metadataReader): array
    {
        $principal = $this->principal($connection);
        $grantee = $principal['grantee'];
        if ($connection->select('SHOW GRANTS FOR PUBLIC') !== [] || $connection->select('SELECT ROLE_NAME FROM information_schema.APPLICABLE_ROLES') !== [] || $connection->selectOne('SELECT ROLE_NAME FROM information_schema.ENABLED_ROLES WHERE ROLE_NAME IS NOT NULL') !== null) {
            throw InPlaceDatabaseRefusalReason::Privilege->exception('PUBLIC and account roles prevent a restricted in-place proof.');
        }

        foreach ($connection->select('SELECT PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ?', [$grantee]) as $grant) {
            if ($grant->PRIVILEGE_TYPE !== 'USAGE' || $grant->IS_GRANTABLE !== 'NO') {
                throw InPlaceDatabaseRefusalReason::Privilege->exception('In-place accounts must not have global privileges or grant options.');
            }
        }

        foreach (['SCHEMA_PRIVILEGES', 'COLUMN_PRIVILEGES'] as $catalog) {
            if ($connection->selectOne('SELECT 1 AS present FROM information_schema.' . $catalog . ' WHERE GRANTEE = ? LIMIT 1', [$grantee]) !== null) {
                throw InPlaceDatabaseRefusalReason::Privilege->exception('In-place accounts require table-level privileges only.');
            }
        }

        $tables = [];
        $normalised = [];
        foreach ($connection->select('SELECT TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ? ORDER BY TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE', [$grantee]) as $grant) {
            $application = $grant->TABLE_SCHEMA === $connection->getDatabaseName();
            $metadata = $metadataReader && $grant->TABLE_SCHEMA === 'mysql' && in_array($grant->TABLE_NAME, ['procs_priv', 'proxies_priv', 'roles_mapping', 'func'], true);
            $allowed = $grant->PRIVILEGE_TYPE === 'SELECT' || ($application && in_array($grant->TABLE_NAME, $writeTables, true) && in_array($grant->PRIVILEGE_TYPE, ['INSERT', 'UPDATE', 'DELETE'], true));
            if ((! $application && ! $metadata) || ! $allowed || $grant->IS_GRANTABLE !== 'NO') {
                throw InPlaceDatabaseRefusalReason::Privilege->exception('A database privilege exceeds the in-place table allowlist.');
            }

            if ($application) {
                $tables[] = $grant->TABLE_NAME;
            }

            $normalised[] = (array) $grant;
        }

        foreach ($writeTables as $table) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
                if (! in_array(['TABLE_SCHEMA' => $connection->getDatabaseName(), 'TABLE_NAME' => $table, 'PRIVILEGE_TYPE' => $privilege, 'IS_GRANTABLE' => 'NO'], $normalised, true)) {
                    throw InPlaceDatabaseRefusalReason::Privilege->exception('The restricted writer lacks a planned table privilege.');
                }
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);

        return ['principal' => $principal['digest'], 'account' => $principal['account'], 'tables' => $tables, 'grants' => $normalised];
    }

    private function identifier(string $value): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/D', $value) !== 1) {
            throw new ImportRefusedException('Unsupported physical database identifier.');
        }

        return $value;
    }
}
