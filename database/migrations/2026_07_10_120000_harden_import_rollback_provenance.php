<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\Core\Support\UserKeyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TableName::resolve('import_rollback_reports'), function (Blueprint $table): void {
            $table->json('provenance')->nullable()->after('created_models');
            $table->string('provenance_signature', 64)->nullable()->after('provenance');
        });

        Schema::create(TableName::resolve('import_rollback_audits'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_rollback_report_id')
                ->constrained(TableName::resolve('import_rollback_reports'), indexName: TableName::namedIndex('import_rollback_audits', 'import_rollback_audits_report_fk'))
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->type(UserKeyType::columnType())
                ->nullable()
                ->constrained('users', indexName: TableName::namedIndex('import_rollback_audits', 'import_rollback_audits_actor_fk'))
                ->nullOnDelete();
            $table->boolean('dry_run');
            $table->string('outcome');
            $table->unsignedInteger('matched');
            $table->unsignedInteger('deleted');
            $table->json('skipped');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_rollback_report_id', 'created_at'], TableName::namedIndex('import_rollback_audits', 'import_rollback_audits_report_created_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('import_rollback_audits'));

        Schema::table(TableName::resolve('import_rollback_reports'), function (Blueprint $table): void {
            $table->dropColumn(['provenance', 'provenance_signature']);
        });
    }
};
