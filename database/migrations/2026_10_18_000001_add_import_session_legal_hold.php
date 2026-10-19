<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('import_sessions', 'legal_hold')) {
            Schema::table('import_sessions', function (Blueprint $table): void {
                $table->boolean('legal_hold')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('import_sessions', 'legal_hold')) {
            Schema::table('import_sessions', function (Blueprint $table): void {
                $table->dropColumn('legal_hold');
            });
        }
    }
};
