<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn(TableName::resolve('import_sessions'), 'legal_hold')) {
            Schema::table(TableName::resolve('import_sessions'), function (Blueprint $table): void {
                $table->boolean('legal_hold')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn(TableName::resolve('import_sessions'), 'legal_hold')) {
            Schema::table(TableName::resolve('import_sessions'), function (Blueprint $table): void {
                $table->dropColumn('legal_hold');
            });
        }
    }
};
