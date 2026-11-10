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
        Schema::table(TableName::resolve('import_source_keys'), static function (Blueprint $table): void {
            $table->string('source_identity', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('import_source_keys'), static function (Blueprint $table): void {
            $table->dropColumn('source_identity');
        });
    }
};
