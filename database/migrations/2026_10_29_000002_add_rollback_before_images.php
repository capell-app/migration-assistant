<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rollback_reports', function (Blueprint $table): void {
            $table->longText('before_images')->nullable();
        });
        Schema::table('import_rollback_audits', function (Blueprint $table): void {
            $table->unsignedInteger('restored')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('import_rollback_audits', function (Blueprint $table): void {
            $table->dropColumn('restored');
        });
        Schema::table('import_rollback_reports', function (Blueprint $table): void {
            $table->dropColumn('before_images');
        });
    }
};
