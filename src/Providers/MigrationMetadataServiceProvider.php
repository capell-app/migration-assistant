<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {

        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/migration-assistant', __DIR__ . '/../../database/migrations', [
                '2026_05_10_190859_01_create_import_sessions_table' => 'import_sessions',
                '2026_05_10_190859_02_create_import_rollback_reports_table' => 'import_rollback_reports',
                '2026_07_10_120000_harden_import_rollback_provenance' => 'import_rollback_audits',
                '2026_10_29_000001_create_import_source_keys_table' => 'import_source_keys',
                '2026_10_30_000002_create_database_source_labels_table' => ['database_source_labels', 'database_source_label_locks'],
            ]);
        });
    }
}
