<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Enums\PackageType;
use Capell\MigrationAssistant\Models\ImportRollbackAudit;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ManifestValidator;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Support\ImportSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MigrationAssistantHealthCheck implements ChecksExtensionHealth
{
    private const string PACKAGE_READER_KEY = 'migration-assistant.package-reader';

    private const string ROLLBACK_REPORT_KEY = 'migration-assistant.rollback-report';

    private const string MEDIA_INGEST_KEY = 'migration-assistant.media-ingest';

    /**
     * @var array<string, class-string<Model>>
     */
    private const array MODELS_BY_MORPH_ALIAS = [
        'import_session' => ImportSession::class,
        'import_rollback_report' => ImportRollbackReport::class,
        'import_rollback_audit' => ImportRollbackAudit::class,
    ];

    /**
     * Flat-file source extensions the package ships readers for.
     *
     * @var list<string>
     */
    private const array REQUIRED_SOURCE_EXTENSIONS = ['csv', 'xml'];

    /**
     * @var list<string>
     */
    private const array POSITIVE_PACKAGE_LIMIT_KEYS = [
        'migration-assistant.limits.max_metadata_json_bytes',
        'migration-assistant.limits.max_payload_json_bytes',
        'migration-assistant.limits.max_package_uncompressed_bytes',
    ];

    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(?string $key = null): Collection
    {
        $check = new self;

        if (is_string($key) && $key !== '') {
            $result = $check->diagnosticForKey($key);

            return $result instanceof DoctorCheckResultData
                ? collect([$result])
                : collect();
        }

        return collect($check->allDiagnostics());
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    /**
     * Asserts package reader dependencies, source readers, and size limits
     * are ready enough for upload validation to execute.
     */
    public function packageReaderCheck(): DoctorCheckResultData
    {
        $missingServices = $this->missingPackageReaderServices();
        $missingTables = $this->missingTables([ImportSession::class]);
        $invalidLimitKeys = $this->invalidPositivePackageLimitKeys();
        $unsupportedExtensions = $this->unsupportedSourceExtensions();
        $manifestValidates = $this->manifestValidationIsReachable();
        $passed = $missingServices === []
            && $missingTables === []
            && $invalidLimitKeys === []
            && $unsupportedExtensions === []
            && $manifestValidates;

        return new DoctorCheckResultData(
            label: (string) __('migration-assistant::imports.health.package_reader.label'),
            passed: $passed,
            message: $passed
                ? (string) __('migration-assistant::imports.health.package_reader.passed')
                : (string) __('migration-assistant::imports.health.package_reader.failed', [
                    'services' => $missingServices === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $missingServices),
                    'tables' => $missingTables === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $missingTables),
                    'limits' => $invalidLimitKeys === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $invalidLimitKeys),
                    'extensions' => $unsupportedExtensions === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $unsupportedExtensions),
                    'manifest' => $manifestValidates
                        ? (string) __('migration-assistant::imports.health.ok')
                        : (string) __('migration-assistant::imports.health.failed_status'),
                ]),
            remediation: $passed
                ? null
                : (string) __('migration-assistant::imports.health.package_reader.remediation'),
        );
    }

    /**
     * Asserts rollback-report persistence can capture created model rows.
     */
    public function rollbackReportCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables([ImportRollbackReport::class, ImportRollbackAudit::class]);
        $unregisteredAliases = $this->unregisteredMorphAliases([ImportRollbackReport::class, ImportRollbackAudit::class]);
        $passed = $missingTables === [] && $unregisteredAliases === [];

        return new DoctorCheckResultData(
            label: (string) __('migration-assistant::imports.health.rollback_report.label'),
            passed: $passed,
            message: $passed
                ? (string) __('migration-assistant::imports.health.rollback_report.passed')
                : (string) __('migration-assistant::imports.health.rollback_report.failed', [
                    'tables' => $missingTables === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $missingTables),
                    'aliases' => $unregisteredAliases === [] ? (string) __('migration-assistant::imports.health.none') : implode(', ', $unregisteredAliases),
                ]),
            remediation: $passed
                ? null
                : (string) __('migration-assistant::imports.health.rollback_report.remediation'),
        );
    }

    /**
     * Asserts media ingest dependencies are reachable and byte limits are live.
     */
    public function mediaIngestCheck(): DoctorCheckResultData
    {
        $maximumMediaBytes = $this->configuredMaximumMediaBytes();
        $passed = $maximumMediaBytes > 0;

        return new DoctorCheckResultData(
            label: (string) __('migration-assistant::imports.health.media_ingest.label'),
            passed: $passed,
            message: $passed
                ? (string) __('migration-assistant::imports.health.media_ingest.passed')
                : (string) __('migration-assistant::imports.health.media_ingest.failed'),
            remediation: $passed
                ? null
                : (string) __('migration-assistant::imports.health.media_ingest.remediation'),
        );
    }

    /**
     * Asserts the import-session and rollback-report storage tables exist.
     */
    public function storageTablesCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables();

        return new DoctorCheckResultData(
            label: (string) __('migration-assistant::imports.health.storage_tables.label'),
            passed: $missingTables === [],
            message: $missingTables === []
                ? (string) __('migration-assistant::imports.health.storage_tables.passed')
                : (string) __('migration-assistant::imports.health.storage_tables.failed', ['tables' => implode(', ', $missingTables)]),
            remediation: $missingTables === []
                ? null
                : (string) __('migration-assistant::imports.health.storage_tables.remediation'),
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
            label: (string) __('migration-assistant::imports.health.morph_aliases.label'),
            passed: $unregisteredAliases === [],
            message: $unregisteredAliases === []
                ? (string) __('migration-assistant::imports.health.morph_aliases.passed')
                : (string) __('migration-assistant::imports.health.morph_aliases.failed', ['aliases' => implode(', ', $unregisteredAliases)]),
            remediation: $unregisteredAliases === []
                ? null
                : (string) __('migration-assistant::imports.health.morph_aliases.remediation'),
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
            label: (string) __('migration-assistant::imports.health.source_readers.label'),
            passed: $unsupportedExtensions === [],
            message: $unsupportedExtensions === []
                ? (string) __('migration-assistant::imports.health.source_readers.passed')
                : (string) __('migration-assistant::imports.health.source_readers.failed', ['extensions' => implode(', ', $unsupportedExtensions)]),
            remediation: $unsupportedExtensions === []
                ? null
                : (string) __('migration-assistant::imports.health.source_readers.remediation'),
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
            label: (string) __('migration-assistant::imports.health.media_limit.label'),
            passed: $maximumMediaBytes > 0,
            message: $maximumMediaBytes > 0
                ? (string) __('migration-assistant::imports.health.media_limit.passed')
                : (string) __('migration-assistant::imports.health.media_limit.failed'),
            remediation: $maximumMediaBytes > 0
                ? null
                : (string) __('migration-assistant::imports.health.media_limit.remediation'),
        );
    }

    /**
     * @param  list<class-string<Model>>|null  $modelClasses
     * @return list<string>
     */
    public function missingTables(?array $modelClasses = null): array
    {
        $missingTables = [];

        foreach ($this->requiredTableNames($modelClasses) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                $missingTables[] = $tableName;
            }
        }

        return $missingTables;
    }

    /**
     * @param  list<class-string<Model>>|null  $modelClasses
     * @return list<string>
     */
    public function unregisteredMorphAliases(?array $modelClasses = null): array
    {
        $unregisteredAliases = [];

        foreach (self::MODELS_BY_MORPH_ALIAS as $morphAlias => $modelClass) {
            if ($modelClasses !== null && ! in_array($modelClass, $modelClasses, true)) {
                continue;
            }

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
        return $this->configuredPositiveInteger('migration-assistant.limits.max_media_bytes');
    }

    /**
     * @return list<string>
     */
    public function missingPackageReaderServices(): array
    {
        $serviceClasses = [
            PackageReader::class,
            ManifestValidator::class,
        ];
        $missingServices = [];

        foreach ($serviceClasses as $serviceClass) {
            try {
                resolve($serviceClass);
            } catch (Throwable) {
                $missingServices[] = $serviceClass;
            }
        }

        return $missingServices;
    }

    /**
     * @return list<string>
     */
    public function invalidPositivePackageLimitKeys(): array
    {
        $invalidKeys = [];

        foreach (self::POSITIVE_PACKAGE_LIMIT_KEYS as $configKey) {
            if ($this->configuredPositiveInteger($configKey) <= 0) {
                $invalidKeys[] = $configKey;
            }
        }

        return $invalidKeys;
    }

    public function manifestValidationIsReachable(): bool
    {
        try {
            $manifest = new PackageManifest(
                packageType: PackageType::PageExport,
                capellVersion: app()->version(),
                exportedAt: CarbonImmutable::now('UTC'),
                sourceEnvironment: 'diagnostics',
                sourceLiveVersionId: null,
                pageCount: 0,
                siteCount: 0,
                relationCounts: [],
            );

            return resolve(ManifestValidator::class)
                ->validate($manifest->toArray())
                ->isValid();
        } catch (Throwable) {
            return false;
        }
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
     * @return list<DoctorCheckResultData>
     */
    private function allDiagnostics(): array
    {
        return [
            $this->packageReaderCheck(),
            $this->rollbackReportCheck(),
            $this->mediaIngestCheck(),
        ];
    }

    private function diagnosticForKey(string $key): ?DoctorCheckResultData
    {
        return match ($key) {
            self::PACKAGE_READER_KEY => $this->packageReaderCheck(),
            self::ROLLBACK_REPORT_KEY => $this->rollbackReportCheck(),
            self::MEDIA_INGEST_KEY => $this->mediaIngestCheck(),
            default => null,
        };
    }

    private function configuredPositiveInteger(string $key): int
    {
        $limit = config($key);

        return is_numeric($limit) ? (int) $limit : 0;
    }

    /**
     * @param  list<class-string<Model>>|null  $modelClasses
     * @return list<string>
     */
    private function requiredTableNames(?array $modelClasses = null): array
    {
        $tableNames = [];

        foreach (self::MODELS_BY_MORPH_ALIAS as $modelClass) {
            if ($modelClasses !== null && ! in_array($modelClass, $modelClasses, true)) {
                continue;
            }

            $tableNames[] = (new $modelClass)->getTable();
        }

        return $tableNames;
    }
}
