<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_sessions')) {
            return;
        }

        Schema::table('import_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_sessions', 'target_type')) {
                $table->string('target_type', 64)->nullable()->after('user_id')->index();
            }

            if (! Schema::hasColumn('import_sessions', 'target_id')) {
                $table->unsignedBigInteger('target_id')->nullable()->after('target_type')->index();
            }

            if (! Schema::hasColumn('import_sessions', 'target_label')) {
                $table->string('target_label')->nullable()->after('target_id');
            }

            if (! Schema::hasColumn('import_sessions', 'target_url')) {
                $table->string('target_url')->nullable()->after('target_label');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_sessions')) {
            return;
        }

        Schema::table('import_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('import_sessions', 'target_id')) {
                $table->dropIndex(['target_id']);
                $table->dropColumn('target_id');
            }

            if (Schema::hasColumn('import_sessions', 'target_url')) {
                $table->dropColumn('target_url');
            }

            if (Schema::hasColumn('import_sessions', 'target_label')) {
                $table->dropColumn('target_label');
            }

            if (Schema::hasColumn('import_sessions', 'target_type')) {
                $table->dropIndex(['target_type']);
                $table->dropColumn('target_type');
            }
        });
    }
};
