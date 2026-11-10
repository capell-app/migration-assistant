<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\Core\Models\Site;
use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Data\DatabaseImportWritePlan;
use Capell\MigrationAssistant\Data\SourceKeyImportResult;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Models\ImportSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SourceKeyImporter
{
    /**
     * @template T of Model
     *
     * @param  array<string, mixed>  $row
     * @param  class-string<T>  $targetClass
     * @param  callable(T, array<string, mixed>): T  $apply
     */
    public function import(ImportSession $session, string $entityType, string $sourceKey, array $row, string $targetClass, callable $apply, bool $force = false): SourceKeyImportResult
    {
        $manifest = $session->manifest;
        $plan = is_array($manifest) && array_key_exists('write_plan', $manifest) ? DatabaseImportWritePlan::fromArray(is_array($manifest['write_plan']) ? $manifest['write_plan'] : []) : null;
        if ($plan !== null) {
            $plan->assertTargetClass($targetClass);

            return $plan->within(fn (): SourceKeyImportResult => $this->importBound($session, $entityType, $sourceKey, $row, $targetClass, $apply, $force, $plan));
        }

        return $this->importBound($session, $entityType, $sourceKey, $row, $targetClass, $apply, $force, null);
    }

    /**
     * @template T of Model
     *
     * @param  array<string, mixed>  $row
     * @param  class-string<T>  $targetClass
     * @param  callable(T, array<string, mixed>): T  $apply
     */
    private function importBound(ImportSession $session, string $entityType, string $sourceKey, array $row, string $targetClass, callable $apply, bool $force, ?DatabaseImportWritePlan $plan): SourceKeyImportResult
    {
        $connection = DB::connection($plan?->writerConnection);
        $environment = $session->source_environment;
        $manifest = $session->manifest;
        $sourceIdentity = is_array($manifest) ? ($manifest['source_identity'] ?? null) : null;
        if ($environment === 'voyager-db' && (! is_string($sourceIdentity) || preg_match('/\A[a-f0-9]{64}\z/', $sourceIdentity) !== 1)) {
            throw new ImportRefusedException('Voyager source identity is required.');
        }

        $siteId = $session->target_type === 'site' ? $session->target_id : null;
        if ($session->target_type === 'site' && (! is_int($siteId) || $siteId <= 0)) {
            throw new InvalidArgumentException('Import session requires a valid site.');
        }

        if (! is_string($environment) || $environment === '' || $entityType === '' || $sourceKey === '' || mb_strlen($environment) > 128 || mb_strlen($entityType) > 128) {
            throw new InvalidArgumentException('Invalid import source key.');
        }

        if ($plan === null && ((new $targetClass)->getConnectionName() ?? DB::getDefaultConnection()) !== DB::getDefaultConnection()) {
            throw new ImportRefusedException('Import target and source keys must use the same database connection.');
        }

        return $connection->transaction(function () use ($connection, $plan, $environment, $entityType, $sourceKey, $row, $targetClass, $apply, $force, $siteId, $sourceIdentity): SourceKeyImportResult {
            $identity = ['site_scope' => $siteId === null ? 'global' : 'site:' . $siteId, 'source_environment' => $environment, 'entity_type' => $entityType, 'source_key_hash' => hash('sha256', $sourceKey)];
            $mapping = $connection->table(TableName::resolve('import_source_keys'))->where($identity)->lockForUpdate()->first();
            $sourceChecksum = $this->checksum($row);
            $target = null;
            $before = null;
            $originalKey = null;

            if ($mapping !== null) {
                if ($environment === 'voyager-db' && $mapping->source_identity !== $sourceIdentity) {
                    throw new ImportRefusedException('Source key identity does not match.');
                }

                if ($mapping->target_class !== $targetClass) {
                    throw new ImportRefusedException('Source key target class changed.');
                }

                $target = ($plan === null ? $targetClass::query() : $targetClass::on($plan->writerConnection))->withoutGlobalScopes()->whereKey($mapping->target_id)->lockForUpdate()->first();
                if (! $target instanceof $targetClass) {
                    throw new ImportRefusedException('Source key target no longer exists.');
                }

                $this->assertSite($target, $siteId);
                $key = $target->getKey();
                if (! is_int($key) && ! is_string($key)) {
                    throw new ImportRefusedException('Source key target requires a scalar key.');
                }

                $edited = ! hash_equals($mapping->target_checksum, $this->checksum($target->getAttributes()));
                if ($edited && ! $force) {
                    return new SourceKeyImportResult('conflict', $key);
                }

                if (! $edited && hash_equals($mapping->source_checksum, $sourceChecksum)) {
                    return new SourceKeyImportResult('skipped', $key);
                }

                $before = $target->getAttributes();
                $originalKey = $key;
            }

            $candidate = $target ?? new $targetClass;
            if ($plan !== null) {
                $candidate->setConnection($plan->writerConnection);
                $plan->assertTarget($candidate);
            }
            $saved = $apply($candidate, $row);
            $plan?->assertTarget($saved);
            $key = $saved->getKey();
            if ($plan === null && ($saved->getConnectionName() ?? DB::getDefaultConnection()) !== DB::getDefaultConnection()) {
                throw new ImportRefusedException('Import target and source keys must use the same database connection.');
            }

            if (! $saved instanceof $targetClass || ! $saved->exists || (! is_int($key) && ! is_string($key)) || ($originalKey !== null && (string) $key !== (string) $originalKey)) {
                throw new ImportRefusedException('Import callback must return the persisted target.');
            }

            $this->assertCreatedCandidateForFirstImport($mapping, $saved, $candidate);

            $saved = ($plan === null ? $targetClass::query() : $targetClass::on($plan->writerConnection))->withoutGlobalScopes()->whereKey($key)->lockForUpdate()->firstOrFail();
            $this->assertSite($saved, $siteId);
            $values = ['target_class' => $targetClass, 'target_id' => (string) $key, 'source_checksum' => $sourceChecksum, 'target_checksum' => $this->checksum($saved->getAttributes()), 'updated_at' => now()];
            if (is_string($sourceIdentity)) {
                $values['source_identity'] = $sourceIdentity;
            }

            if ($mapping === null) {
                $mappingId = $connection->table(TableName::resolve('import_source_keys'))->insertGetId([...$identity, ...$values, 'created_at' => now()]);
            } else {
                $mappingId = $mapping->id;
                $connection->table(TableName::resolve('import_source_keys'))->where('id', $mappingId)->update($values);
            }

            return new SourceKeyImportResult($mapping === null ? 'created' : 'updated', $key, $before, [
                'id' => $mappingId,
                'target_class' => $targetClass,
                'target_id' => (string) $key,
                'source_checksum' => $sourceChecksum,
                'target_checksum' => $values['target_checksum'],
                'previous_source_checksum' => $mapping?->source_checksum,
                'previous_target_checksum' => $mapping?->target_checksum,
            ]);
        });
    }

    private function assertCreatedCandidateForFirstImport(?object $mapping, Model $saved, Model $candidate): void
    {
        if ($mapping !== null) {
            return;
        }

        if ($saved !== $candidate || ! $saved->wasRecentlyCreated) {
            throw new ImportRefusedException('First import must create the supplied new target.');
        }
    }

    private function assertSite(Model $target, ?int $siteId): void
    {
        $attributes = $target->getAttributes();
        if ($target instanceof Site) {
            $targetSite = $target->getKey();
        } elseif (array_key_exists('site_id', $attributes)) {
            $targetSite = $target->getAttribute('site_id');
        } else {
            return;
        }

        if ($siteId === null || ! is_scalar($targetSite) || (string) $targetSite !== (string) $siteId) {
            throw new ImportRefusedException('Import target site does not match the session site.');
        }
    }

    /** @param array<array-key, mixed> $value */
    private function checksum(array $value): string
    {
        $normalise = static function (mixed $item) use (&$normalise): mixed {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item);
                }

                foreach ($item as $key => $child) {
                    $item[$key] = $normalise($child);
                }
            }

            return $item;
        };

        return hash('sha256', json_encode($normalise($value), JSON_THROW_ON_ERROR));
    }
}
