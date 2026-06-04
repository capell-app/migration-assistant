<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MigrationAssistantHealthCheck implements ChecksExtensionHealth
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const array MODELS_BY_MORPH_ALIAS = [
        'import_session' => ImportSession::class,
        'import_rollback_report' => ImportRollbackReport::class,
    ];

    /**
     * Flat-file source extensions the package ships readers for.
     *
     * @var list<string>
     */
    private const array REQUIRED_SOURCE_EXTENSIONS = ['csv', 'xml'];

    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->storageTablesCheck(),
            $check->modelMorphAliasCheck(),
            $check->importSourceReadersCheck(),
            $check->mediaIngestLimitCheck(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    /**
     * Asserts the import-session and rollback-report storage tables exist.
     */
    public function storageTablesCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables();

        return new DoctorCheckResultData(
            label: 'Migration Assistant storage tables',
            passed: $missingTables === [],
            message: $missingTables === []
                ? 'The import session and rollback report tables are present.'
                : 'Missing tables: ' . implode(', ', $missingTables) . '.',
            remediation: $missingTables === []
                ? null
                : 'Run the Capell migrations to create the Migration Assistant storage tables.',
        );
    }

    /**
     * Asserts the import models are discoverable through the morph map so
     * rollback reports can resolve the records they created.
     */
    public function modelMorphAliasCheck(): DoctorCheckResultData
    {
        $unregisteredAliases = $this->unregisteredMorphAliases();

        return new DoctorCheckResultData(
            label: 'Migration Assistant model morph aliases',
            passed: $unregisteredAliases === [],
            message: $unregisteredAliases === []
                ? 'Import session and rollback report models are registered in the morph map.'
                : 'Unregistered morph aliases: ' . implode(', ', $unregisteredAliases) . '.',
            remediation: $unregisteredAliases === []
                ? null
                : 'Ensure MigrationAssistantServiceProvider registers the import models.',
        );
    }

    /**
     * Asserts the package can resolve a source reader for every flat-file
     * format it advertises, so package import can actually parse uploads.
     */
    public function importSourceReadersCheck(): DoctorCheckResultData
    {
        $unsupportedExtensions = $this->unsupportedSourceExtensions();

        return new DoctorCheckResultData(
            label: 'Migration Assistant source readers',
            passed: $unsupportedExtensions === [],
            message: $unsupportedExtensions === []
                ? 'Source readers are registered for the CSV and XML formats.'
                : 'No source reader is registered for: ' . implode(', ', $unsupportedExtensions) . '.',
            remediation: $unsupportedExtensions === []
                ? null
                : 'Ensure MigrationAssistantServiceProvider registers the CSV and XML source readers.',
        );
    }

    /**
     * Asserts a positive media size limit is configured so media ingest can
     * enforce per-file limits during import.
     */
    public function mediaIngestLimitCheck(): DoctorCheckResultData
    {
        $maximumMediaBytes = $this->configuredMaximumMediaBytes();

        return new DoctorCheckResultData(
            label: 'Migration Assistant media ingest limit',
            passed: $maximumMediaBytes > 0,
            message: $maximumMediaBytes > 0
                ? 'A positive per-file media size limit is configured for media ingest.'
                : 'No positive media size limit is configured; media ingest cannot enforce per-file limits.',
            remediation: $maximumMediaBytes > 0
                ? null
                : 'Set migration-assistant.limits.max_media_bytes to a positive byte count.',
        );
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        $missingTables = [];

        foreach ($this->requiredTableNames() as $tableName) {
            if (! Schema::hasTable($tableName)) {
                $missingTables[] = $tableName;
            }
        }

        return $missingTables;
    }

    /**
     * @return list<string>
     */
    public function unregisteredMorphAliases(): array
    {
        $unregisteredAliases = [];

        foreach (self::MODELS_BY_MORPH_ALIAS as $morphAlias => $modelClass) {
            if (Relation::getMorphedModel($morphAlias) !== $modelClass) {
                $unregisteredAliases[] = $morphAlias;
            }
        }

        return $unregisteredAliases;
    }

    /**
     * @return list<string>
     */
    public function unsupportedSourceExtensions(): array
    {
        $registry = $this->resolveSourceRegistry();

        if (! $registry instanceof ImportSourceRegistry) {
            return self::REQUIRED_SOURCE_EXTENSIONS;
        }

        $unsupported = [];

        foreach (self::REQUIRED_SOURCE_EXTENSIONS as $extension) {
            try {
                $registry->readerFor('source.' . $extension);
            } catch (Throwable) {
                $unsupported[] = $extension;
            }
        }

        return $unsupported;
    }

    public function configuredMaximumMediaBytes(): int
    {
        $limit = config('migration-assistant.limits.max_media_bytes');

        return is_int($limit) ? $limit : 0;
    }

    private function resolveSourceRegistry(): ?ImportSourceRegistry
    {
        try {
            $registry = resolve(ImportSourceRegistry::class);
        } catch (Throwable) {
            return null;
        }

        return $registry instanceof ImportSourceRegistry ? $registry : null;
    }

    /**
     * @return list<string>
     */
    private function requiredTableNames(): array
    {
        return [
            (new ImportSession)->getTable(),
            (new ImportRollbackReport)->getTable(),
        ];
    }
}
