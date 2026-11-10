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
        Schema::create(TableName::resolve('import_source_keys'), function (Blueprint $table): void {
            $table->id();
            $table->string('site_scope', 32);
            $table->string('source_environment', 128);
            $table->string('entity_type', 128);
            $table->string('source_key_hash', 64);
            $table->string('target_class');
            $table->string('target_id');
            $table->string('source_checksum', 64);
            $table->string('target_checksum', 64);
            $table->timestamps();
            $table->index(['target_class', 'target_id'], TableName::namedIndex('import_source_keys', 'import_source_keys_target_index'));
            $table->unique(['site_scope', 'source_environment', 'entity_type', 'source_key_hash'], TableName::namedIndex('import_source_keys', 'import_source_keys_identity_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('import_source_keys'));
    }
};
