<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;

final class RollbackProvenance
{
    /**
     * Provenance type of a role assignment row. A pivot row has no model of its own, so it is journalled by its
     * identifying triple and rolled back by deleting that row.
     */
    public const string ROLE_PAIR = 'model_has_roles';

    /**
     * @var array<string, class-string<Model>>
     */
    private const array TARGETS = [
        'page' => Page::class,
        'site' => Site::class,
        'site_domain' => SiteDomain::class,
        'media' => Media::class,
    ];

    /**
     * @return array{version: int, report_uuid: string, session_uuid: string, session_user_id: int|null, executed_at: string, entries: list<array{type: string, id: int|string, site_id: int|null, created_at: string, updated_at: string}>}
     */
    public static function create(string $reportUuid, ImportSession $session, ImportExecutionReport $report): array
    {
        $entries = [];

        foreach ($report->createdModels() as $createdModel) {
            $modelClass = $createdModel['class'];

            if (! is_subclass_of($modelClass, Model::class)) {
                throw new LogicException('Rollback provenance target must be an Eloquent model.');
            }

            $model = self::findModel($modelClass, $createdModel['id']);

            if (! $model instanceof Model) {
                throw new LogicException('Rollback provenance can only be created for persisted allowlisted import targets.');
            }

            $entries[] = self::entryFor($model);
        }

        return [
            'version' => 1,
            'report_uuid' => $reportUuid,
            'session_uuid' => $session->uuid,
            'session_user_id' => $session->user_id,
            'executed_at' => self::timestamp($session->executed_at ?? now()),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $provenance
     */
    public static function sign(array $provenance): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('Rollback provenance requires an application key.');
        }

        return hash_hmac('sha256', self::canonicalJson($provenance), $key);
    }

    /**
     * @param  array<array-key, mixed>  $provenance
     */
    public static function hasValidSignature(array $provenance, ?string $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            return hash_equals(self::sign($provenance), $signature);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    public static function findEntryModel(array $entry, bool $lockForUpdate = false, ?string $connection = null, ?string $targetClass = null): ?Model
    {
        $type = $entry['type'] ?? null;
        $id = $entry['id'] ?? null;

        if (! is_string($type) || ! self::hasTargetType($type) || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        $class = $targetClass ?? (self::targets()[$type] ?? null);
        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }
        $query = ($connection === null ? $class::query() : $class::on($connection))->withoutGlobalScopes()->whereKey($id);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public static function isAllowedType(string $type): bool
    {
        return $type === self::ROLE_PAIR || self::hasTargetType($type);
    }

    /**
     * @return array{type: string, id: string, site_id: null, operation: string, role_id: int|string, model_type: string, model_id: int|string, team_id: int|string|null, mapping: null, before_hash: string, after_hash: string}
     */
    public static function rolePairEntry(int|string $roleId, string $modelType, int|string $modelId, int|string|null $teamId = null): array
    {
        $pair = ['role_id' => $roleId, 'model_type' => $modelType, 'model_id' => $modelId, 'team_id' => $teamId];

        return [
            'type' => self::ROLE_PAIR,
            'id' => $roleId . ':' . $modelType . ':' . $modelId . ($teamId === null ? '' : ':team' . $teamId),
            'site_id' => null,
            'operation' => 'created',
            ...$pair,
            'mapping' => null,
            'before_hash' => hash('sha256', json_encode(null, JSON_THROW_ON_ERROR)),
            'after_hash' => hash('sha256', json_encode($pair, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * The configured pivot table, role key and model key of role assignments.
     *
     * @return array{table: string, role: string, model: string, team: string|null} team is the team foreign key column when permission teams are enabled
     */
    public static function rolePairColumns(): array
    {
        $table = config('permission.table_names.model_has_roles');
        $role = config('permission.column_names.role_pivot_key');
        $model = config('permission.column_names.model_morph_key');
        $team = config('permission.column_names.team_foreign_key');

        return [
            'table' => is_string($table) && $table !== '' ? $table : 'model_has_roles',
            'role' => is_string($role) && $role !== '' ? $role : 'role_id',
            'model' => is_string($model) && $model !== '' ? $model : 'model_id',
            'team' => config('permission.teams') && is_string($team) && $team !== '' ? $team : null,
        ];
    }

    /**
     * @return array{type: string, id: int|string, site_id: int|null, created_at: string, updated_at: string}
     */
    public static function entryFor(Model $model, ?string $typeOverride = null): array
    {
        $type = $typeOverride ?? array_search($model::class, self::targets(), true);
        $siteId = $model instanceof Site ? $model->getKey() : (array_key_exists('site_id', $model->getAttributes()) ? $model->getAttribute('site_id') : null);
        $createdAt = self::modelTimestamp($model, $model->getCreatedAtColumn());
        $updatedAt = self::modelTimestamp($model, $model->getUpdatedAtColumn());

        if (! is_string($type) || ($siteId !== null && ! is_int($siteId)) || $createdAt === null || $updatedAt === null) {
            throw new LogicException('Rollback provenance requires an allowlisted model with immutable timestamps.');
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('Rollback provenance requires a scalar model key.');
        }

        return [
            'type' => $type,
            'id' => $key,
            'site_id' => $siteId,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    public static function matchesEntry(Model $model, array $entry, ?string $typeOverride = null): ?string
    {
        try {
            $current = self::entryFor($model, $typeOverride);
        } catch (LogicException) {
            return 'invalid_target';
        }

        if ($current['type'] !== ($entry['type'] ?? null) || $current['id'] !== ($entry['id'] ?? null)) {
            return 'invalid_target';
        }

        if ($current['site_id'] !== ($entry['site_id'] ?? null)) {
            return 'cross_site';
        }

        if ($current['created_at'] !== ($entry['created_at'] ?? null)) {
            return 'provenance_mismatch';
        }

        if ($current['updated_at'] !== ($entry['updated_at'] ?? null)) {
            return 'edited_after_import';
        }

        return null;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private static function findModel(string $class, int|string $id): ?Model
    {
        if (! in_array($class, self::targets(), true)) {
            return null;
        }

        return $class::query()->withoutGlobalScopes()->whereKey($id)->first();
    }

    private static function hasTargetType(string $type): bool
    {
        return array_key_exists($type, self::targets());
    }

    /** @return array<string, class-string<Model>> */
    private static function targets(): array
    {
        $targets = self::TARGETS;
        $userClass = config('auth.providers.users.model');
        if (is_string($userClass) && is_subclass_of($userClass, Model::class)) {
            $targets['user'] = $userClass;
        }

        $configured = config('migration-assistant.rollback_targets', []);
        if (is_array($configured)) {
            foreach ($configured as $type => $class) {
                if (is_string($type) && is_string($class) && is_subclass_of($class, Model::class)) {
                    $targets[$type] = $class;
                }
            }
        }

        return $targets;
    }

    private static function modelTimestamp(Model $model, ?string $column): ?string
    {
        if (! is_string($column) || $column === '') {
            return null;
        }

        $value = $model->getAttribute($column);

        return $value instanceof CarbonInterface ? self::timestamp($value) : null;
    }

    private static function timestamp(CarbonInterface $value): string
    {
        return $value->utc()->format('Y-m-d\\TH:i:s.u\\Z');
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
