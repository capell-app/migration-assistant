<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ROLLBACK_REPORTS_TABLE = 'import_rollback_dashboard' . '-dashboard_reports';

    public function up(): void
    {
        if (Schema::hasTable('import_rollback_reports')) {
            return;
        }

        if (Schema::hasTable(self::LEGACY_ROLLBACK_REPORTS_TABLE)) {
            Schema::rename(self::LEGACY_ROLLBACK_REPORTS_TABLE, 'import_rollback_reports');

            return;
        }

        Schema::create('import_rollback_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('import_session_id')
                ->constrained('import_sessions', indexName: 'import_rollback_reports_session_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', indexName: 'import_rollback_reports_user_fk')
                ->nullOnDelete();
            $table->string('source_filename')->nullable();
            $table->string('source_package_checksum')->nullable();
            $table->json('created_models')->nullable();
            $table->json('summary')->nullable();
            $table->text('manual_instructions');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['import_session_id', 'executed_at'], 'import_rollback_reports_session_executed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rollback_reports');
    }
};
