<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_rollback_reports')) {
            return;
        }

        if (Schema::hasTable('import_rollback_dashboard-dashboard_reports')) {
            Schema::rename('import_rollback_dashboard-dashboard_reports', 'import_rollback_reports');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('import_rollback_dashboard-dashboard_reports')) {
            return;
        }

        if (Schema::hasTable('import_rollback_reports')) {
            Schema::rename('import_rollback_reports', 'import_rollback_dashboard-dashboard_reports');
        }
    }
};
