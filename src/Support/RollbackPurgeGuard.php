<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Decides whether a created row can be purged without cascading into rows the import did not create.
 *
 * A purge fires database ON DELETE CASCADE and forceDeleted listeners, so rows that point at the target (through a
 * foreign key, or through a `*_type`/`*_id` morph pair) and were added after the import would be destroyed with it.
 * The caller removes the import's own children first, since a rollback walks its journal in reverse, so any
 * reference still present belongs to something else.
 */
final class RollbackPurgeGuard
{
    /** The first table still referencing the model, or null when nothing does and a purge is safe. */
    public static function blockingReference(Model $model): ?string
    {
        $key = $model->getKey();
        if (! is_int($key) && ! is_string($key)) {
            return $model->getTable();
        }
        $connection = $model->getConnection();
        $schema = $connection->getSchemaBuilder();
        $table = $model->getTable();
        try {
            $morphs = array_unique([$model->getMorphClass(), $model::class]);
        } catch (ClassMorphViolationException) {
            // An enforced morph map without this model means nothing can reference it polymorphically under an alias.
            $morphs = [$model::class];
        }
        try {
            foreach ($schema->getTables() as $candidate) {
                $name = $candidate['name'];
                foreach ($schema->getForeignKeys($name) as $foreign) {
                    if ($foreign['foreign_table'] !== $table || count($foreign['columns']) !== 1 || count($foreign['foreign_columns']) !== 1 || $foreign['foreign_columns'][0] !== $model->getKeyName()) {
                        continue;
                    }
                    if ($connection->table($name)->where($foreign['columns'][0], $key)->exists()) {
                        return $name;
                    }
                }
                $columns = $schema->getColumnListing($name);
                foreach ($columns as $column) {
                    if (! str_ends_with($column, '_type') || ! in_array(substr($column, 0, -5) . '_id', $columns, true)) {
                        continue;
                    }
                    if ($connection->table($name)->whereIn($column, $morphs)->where(substr($column, 0, -5) . '_id', $key)->exists()) {
                        return $name;
                    }
                }
            }
        } catch (Throwable) {
            // An inspection that cannot finish must not lead to a destructive purge.
            return $table;
        }

        return null;
    }
}
