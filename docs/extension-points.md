# Migration Assistant Extension Points

Migration Assistant imports, exports, reviews, and rolls back content moves between Capell sites. Extension points should describe what a package owns, not patch the import job from the outside.

## Main Extension Points

| Need                       | Extension point                                           |
| -------------------------- | --------------------------------------------------------- |
| Read a new source format   | `ImportSourceReader` registered in `ImportSourceRegistry` |
| Add import targets         | `ImportTargetRegistry::register()`                        |
| Resolve imported relations | `RelationMatchResolverRegistry::register()`               |
| Contribute review rows     | `MigrationAssistantRowContributor`                        |
| Resolve package context    | `MigrationAssistantContextResolver`                       |
| Detect URL/page collisions | `PageCollisionDetector`                                   |
| Resolve page import target | `PageImportTargetResolver`                                |
| Mark relation ownership    | `OwnershipMap::register()`                                |

The package ships CSV and XML readers, default targets for `page`, `type`, and `collection`, and relation resolvers for layouts, types, sites, and media.

## Register a Source Reader

```php
use Capell\MigrationAssistant\Contracts\ImportSourceReader;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;

final class JsonImportSourceReader implements ImportSourceReader
{
    public function supports(string $extension): bool
    {
        return $extension === 'json';
    }

    public function read(string $path): ExternalImportReadResult
    {
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new ExternalImportReadResult(
            sourceType: 'json',
            columns: array_keys($payload[0] ?? []),
            rows: $payload,
            metadata: ['filename' => basename($path)],
        );
    }
}

$this->app->afterResolving(ImportSourceRegistry::class, static function (ImportSourceRegistry $registry): void {
    $registry->register(new JsonImportSourceReader, prepend: true);
});
```

Use `prepend: true` only when the new reader should win over a built-in reader for the same extension.

## Register an Import Target

```php
use Capell\MigrationAssistant\Support\ImportTargetRegistry;
use Vendor\KnowledgeBase\Models\Article;

$this->app->afterResolving(ImportTargetRegistry::class, static function (ImportTargetRegistry $registry): void {
    $registry->register('knowledge_article', Article::class);
});
```

Target keys become part of import payloads. Keep them stable once exported.

## Register Relation Resolvers

Relation resolvers are checked in priority order for each group.

```php
use Capell\MigrationAssistant\Services\Import\Resolvers\KeyedMatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use Vendor\KnowledgeBase\Models\Category;

$this->app->afterResolving(RelationMatchResolverRegistry::class, static function (RelationMatchResolverRegistry $registry): void {
    $registry->register('knowledge_categories', new KeyedMatchResolver(Category::class, keyColumn: 'slug'));
});
```

Use package-specific group names unless the resolver intentionally contributes to a core group such as `layouts`, `blueprints`, `sites`, or `media`.

## Config Keys

| Key                              | Use                                                    |
| -------------------------------- | ------------------------------------------------------ |
| `migration-assistant.enabled`    | Enables the package surface.                           |
| `migration-assistant.disk`       | Storage disk for import/export files.                  |
| `migration-assistant.channels`   | Notification channels for completed or failed imports. |
| `migration-assistant.completed`  | Completion notification settings.                      |
| `migration-assistant.failed`     | Failure notification settings.                         |
| `migration-assistant.connection` | Queue/database connection setting where configured.    |

The package also has model, table, and path config used by import internals. Document those only when a host app needs to change them.

## Database sources for executors

At preview, resolve a configured database connection by name and collect the source table names. Use `DatabaseSourceManifest::preview($connection, $tables, $sourceLabel)` and save its array on the session. The stable, operator-supplied source label distinguishes different databases restored into the same endpoint. Never include credentials in the manifest or reports. The preview fingerprint covers the columns returned by Laravel's schema builder. Preview again if the schema changes.

Database executors extend `DatabaseImportSessionExecutor` and implement `executeDatabase()`. Its final `execute()` validates the manifest and checks the fingerprint before the implementation runs, including direct calls. Register the executor in `ImportSessionExecutorRegistry` as with file-source executors.

For each row, `SourceKeyImporter::import($session, $entityType, $sourceKey, $row, $targetClass, $apply, $force)` uses the session's stable source environment, site context, entity type and source key. Set `target_type` to `site` and `target_id` to the site ID for site-owned targets; sessions without that context can import siteless targets only. The importer checks the target's site before updating it and after the callback. Its result distinguishes created, updated, skipped and conflict outcomes. Persist rollback evidence in the same transaction as imported changes; before-images can contain secrets and require encryption. A conflict indicates the target changed after the previous import. An executor may explicitly force the update after review.

For a new source key, the importer supplies a new unsaved target to the callback; save and return that same instance. The importer callback must write the target on the same database connection as the mapping. Wrap row changes and `CreateDatabaseRollbackReportAction::run()` in one outer transaction. For each created or updated row, pass `new DatabaseRollbackChange($target, $result->beforeImage, $result)` to the report action. Skipped and conflicted rows do not need rollback entries. Global administrators with the rollback permission can execute database rollback; target update or delete policies also apply. Rollback skips targets or source mappings that changed since the import.

Optional rollback model types are configured in `migration-assistant.rollback_targets`; unavailable classes are ignored. Created database rollback entries require a source-key result, while updated entries carry a before-image. Deleted-record before-images are not supported yet. The importer accepts a programmatic force flag, but an operator-facing `--force` option is not supported yet.
