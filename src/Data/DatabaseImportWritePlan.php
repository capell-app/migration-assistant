<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Capell\MigrationAssistant\Actions\InspectDatabaseImportWritePlanAction;
use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Capell\MigrationAssistant\Support\DatabaseImportWriteGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A versioned physical-table contract. Account and grant metadata exist only inside the proof digest. */
final readonly class DatabaseImportWritePlan
{
    /**
     * @param  list<string>  $sourceTables
     * @param  array<class-string<Model>, string>  $targets
     * @param  array<string, string>  $storage
     * @param  list<string>  $readTables
     * @param  list<string>  $configurationKeys
     */
    public function __construct(
        public string $logicalConnection,
        public string $readerConnection,
        public string $writerConnection,
        public array $sourceTables,
        public array $targets,
        public array $storage,
        public array $readTables,
        public array $configurationKeys,
        public string $proofDigest,
        public string $executionMode = 'explicit-profiles',
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $executionMode = $data['execution_mode'] ?? 'explicit-profiles';
        if (! in_array($executionMode, ['explicit-profiles', 'dedicated-process'], true)) {
            throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place execution mode.');
        }

        unset($data['execution_mode']);
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['capability', 'configuration_keys', 'logical_connection', 'proof_digest', 'read_tables', 'reader_connection', 'source_tables', 'storage', 'targets', 'version', 'writer_connection'] || $data['version'] !== 1 || $data['capability'] !== ($executionMode === 'dedicated-process' ? 'mariadb-10.11-dedicated-restricted-writer' : 'mariadb-10.11-settings-restricted-writer')) {
            throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan.');
        }

        foreach (['logical_connection', 'reader_connection', 'writer_connection', 'proof_digest'] as $key) {
            if (! is_string($data[$key]) || $data[$key] === '') {
                throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan.');
            }
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $data['proof_digest']) !== 1) {
            throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan.');
        }

        $sourceTables = self::stringList($data['source_tables']);
        $readTables = self::stringList($data['read_tables']);
        $configurationKeys = self::stringList($data['configuration_keys']);
        $targets = [];
        foreach (self::stringMap($data['targets']) as $class => $table) {
            if (! is_subclass_of($class, Model::class)) {
                throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place model target.');
            }

            $targets[$class] = $table;
        }

        $storage = self::stringMap($data['storage']);

        return new self($data['logical_connection'], $data['reader_connection'], $data['writer_connection'], $sourceTables, $targets, $storage, $readTables, $configurationKeys, $data['proof_digest'], $executionMode);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['version' => 1, 'capability' => $this->executionMode === 'dedicated-process' ? 'mariadb-10.11-dedicated-restricted-writer' : 'mariadb-10.11-settings-restricted-writer', 'logical_connection' => $this->logicalConnection, 'reader_connection' => $this->readerConnection, 'writer_connection' => $this->writerConnection, 'source_tables' => $this->sourceTables, 'targets' => $this->targets, 'storage' => $this->storage, 'read_tables' => $this->readTables, 'configuration_keys' => $this->configurationKeys, 'proof_digest' => $this->proofDigest, ...($this->executionMode === 'dedicated-process' ? ['execution_mode' => $this->executionMode] : [])];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    public function assertUnchanged(): void
    {
        $current = resolve(InspectDatabaseImportWritePlanAction::class)->handle($this->logicalConnection, $this->readerConnection, $this->writerConnection, array_keys($this->targets), $this->configurationKeys, $this->executionMode, $this->sourceTables);
        if (! hash_equals($this->digest(), $current->digest())) {
            throw InPlaceDatabaseRefusalReason::PlanChanged->exception('The in-place physical write plan changed since preview.');
        }
    }

    /** @param class-string<Model> $class */
    public function assertTargetClass(string $class): void
    {
        if (! array_key_exists($class, $this->targets)) {
            throw InPlaceDatabaseRefusalReason::Target->exception('The model is outside the in-place write plan.');
        }

        $model = new $class;
        $configured = $model->getConnectionName();
        if ($configured !== null && $configured !== $this->writerConnection) {
            throw InPlaceDatabaseRefusalReason::Target->exception('The model does not use the restricted writer.');
        }

        if (DB::connection($this->writerConnection)->getTablePrefix() . $model->getTable() !== $this->targets[$class]) {
            throw InPlaceDatabaseRefusalReason::Target->exception('The model table changed since preview.');
        }
    }

    public function assertTarget(Model $model): void
    {
        $this->assertTargetClass($model::class);
        if ($model->getConnectionName() !== $this->writerConnection || $model->getConnection()->getTablePrefix() . $model->getTable() !== $this->targets[$model::class]) {
            throw InPlaceDatabaseRefusalReason::Target->exception('The actual model is outside the restricted writer plan.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(Closure $callback): mixed
    {
        return resolve(DatabaseImportWriteGuard::class)->handle($this, $callback);
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan list.');
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan list.');
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan map.');
        }

        $strings = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw InPlaceDatabaseRefusalReason::Invalid->exception('Invalid in-place write plan map.');
            }

            $strings[$key] = $item;
        }

        return $strings;
    }
}
