<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_source_labels', static function (Blueprint $table): void {
            $table->id();
            $table->string('label', 64)->unique();
            $table->string('connection_identity', 64)->index();
            $table->json('connection_tuple');
            $table->timestamps();
        });
        Schema::create('database_source_label_locks', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('database_source_label_locks')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('database_source_label_locks');
        Schema::dropIfExists('database_source_labels');
    }
};
