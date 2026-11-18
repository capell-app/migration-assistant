<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Closure;

/**
 * Derives the complete configuration a dedicated import child boots from: the parent's configuration with
 * only the three restricted database profiles, the writer as default, and no shared queue, cache or session stores.
 */
final class BuildDedicatedImportConfigurationAction
{
    public const string MARKER = 'migration-assistant.dedicated_process.active';

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function handle(array $configuration, string $inspector, string $reader, string $writer): array
    {
        $names = [$inspector, $reader, $writer];
        if (count(array_unique($names)) !== 3) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('A dedicated import needs three distinct inspector, reader and writer profiles.');
        }

        if (data_get($configuration, 'migration-assistant.dedicated_process.active') === true) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('A dedicated import process cannot launch another dedicated import process.');
        }

        $connections = [];
        foreach ($names as $name) {
            $profile = data_get($configuration, 'database.connections.' . $name);
            if (! is_array($profile) || ! in_array($profile['driver'] ?? null, ['mariadb', 'mysql'], true)) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('Each dedicated import profile must be a configured MariaDB connection.');
            }

            if (is_string($profile['url'] ?? null) && $profile['url'] !== '') {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('Dedicated import profiles cannot use a database URL.');
            }

            $connections[$name] = $profile;
        }

        $database = self::section($configuration, 'database');
        // Only the restricted profiles survive: administrative and ordinary application connections, and Redis
        // credentials, are never written into the child's configuration.
        $configuration['database'] = ['default' => $writer, 'connections' => $connections, 'migrations' => $database['migrations'] ?? 'migrations'];
        $queue = self::section($configuration, 'queue');
        $configuration['queue'] = [...$queue, 'default' => 'sync', 'failed' => [...self::section($queue, 'failed'), 'driver' => 'null']];
        $configuration['cache'] = [...self::section($configuration, 'cache'), 'default' => 'array'];
        $configuration['session'] = [...self::section($configuration, 'session'), 'driver' => 'array'];
        // An import sends no mail and calls no third-party service, so their credentials are not handed to the child.
        $configuration['mail'] = [...self::section($configuration, 'mail'), 'default' => 'array', 'mailers' => ['array' => ['transport' => 'array']]];
        $configuration['services'] = [];
        $assistant = self::section($configuration, 'migration-assistant');
        $configuration['migration-assistant'] = [...$assistant, 'dedicated_process' => [...self::section($assistant, 'dedicated_process'), 'active' => true]];

        $this->assertExportable($configuration, '');

        return $configuration;
    }

    /**
     * @param  array<array-key, mixed>  $configuration
     * @return array<array-key, mixed>
     */
    private static function section(array $configuration, string $key): array
    {
        $section = $configuration[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    private function assertExportable(mixed $value, string $key): void
    {
        if (is_array($value)) {
            foreach ($value as $child => $item) {
                $this->assertExportable($item, ltrim($key . '.' . $child, '.'));
            }

            return;
        }

        // Matches config:cache: objects that var_export can restore (DateInterval, enums) are kept, and the launcher's
        // round trip refuses any other object; closures and resources can never be restored.
        if ($value instanceof Closure || is_resource($value)) {
            throw InPlaceDatabaseRefusalReason::Unsupported->exception(sprintf('The configuration value [%s] cannot be written to a configuration cache.', $key));
        }
    }
}
