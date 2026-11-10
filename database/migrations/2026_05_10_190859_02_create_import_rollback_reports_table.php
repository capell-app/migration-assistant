<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\Core\Support\UserKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string LEGACY_ROLLBACK_REPORTS_TABLE = 'import_rollback_dashboard-dashboard_reports';

    public function up(): void
    {
        if (TableName::existsAndIsOwned('import_rollback_reports', basename(__FILE__, '.php'))) {
            return;
        }

        if (Schema::hasTable(self::LEGACY_ROLLBACK_REPORTS_TABLE)) {
            Schema::rename(self::LEGACY_ROLLBACK_REPORTS_TABLE, TableName::resolve('import_rollback_reports'));

            return;
        }

        Schema::create(TableName::resolve('import_rollback_reports'), function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('import_session_id')
                ->constrained(TableName::resolve('import_sessions'), indexName: TableName::namedIndex('import_rollback_reports', 'import_rollback_reports_session_fk'))
                ->cascadeOnDelete();
            $table->foreignId('user_id')->type(UserKeyType::columnType())
                ->nullable()
                ->constrained('users', indexName: TableName::namedIndex('import_rollback_reports', 'import_rollback_reports_user_fk'))
                ->nullOnDelete();
            $table->string('source_filename')->nullable();
            $table->string('source_package_checksum')->nullable();
            $table->json('created_models')->nullable();
            $table->json('summary')->nullable();
            $table->text('manual_instructions');
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['import_session_id', 'executed_at'], TableName::namedIndex('import_rollback_reports', 'import_rollback_reports_session_executed_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('import_rollback_reports'));
    }
};
