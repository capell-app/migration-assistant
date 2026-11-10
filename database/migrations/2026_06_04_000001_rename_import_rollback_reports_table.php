<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string LEGACY_ROLLBACK_REPORTS_TABLE = 'import_rollback_dashboard-dashboard_reports';

    public function up(): void
    {
        if (Schema::hasTable(TableName::resolve('import_rollback_reports'))) {
            return;
        }

        if (Schema::hasTable(self::LEGACY_ROLLBACK_REPORTS_TABLE)) {
            Schema::rename(self::LEGACY_ROLLBACK_REPORTS_TABLE, TableName::resolve('import_rollback_reports'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::LEGACY_ROLLBACK_REPORTS_TABLE)) {
            return;
        }

        if (Schema::hasTable(TableName::resolve('import_rollback_reports'))) {
            Schema::rename(TableName::resolve('import_rollback_reports'), self::LEGACY_ROLLBACK_REPORTS_TABLE);
        }
    }
};
