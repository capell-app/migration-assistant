<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array SESSION_COLUMNS = [
        'target_url',
        'source_package_path',
        'working_dir',
        'manifest',
        'resolution_map',
        'page_decisions',
        'relation_decisions',
        'validation_results',
        'result_summary',
        'failure_reason',
    ];

    /** @var list<string> */
    private const array ROLLBACK_COLUMNS = [
        'source_filename',
        'created_models',
        'summary',
        'manual_instructions',
    ];

    /** @contract-migration-approved Widens encrypted storage before rewriting existing values. */
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table): void {
            foreach (self::SESSION_COLUMNS as $column) {
                $table->mediumText($column)->nullable()->change();
            }
        });
        Schema::table('import_rollback_reports', function (Blueprint $table): void {
            foreach (self::ROLLBACK_COLUMNS as $column) {
                $table->mediumText($column)->nullable()->change();
            }
        });

        $this->transform('import_sessions', self::SESSION_COLUMNS, encrypt: true);
        $this->transform('import_rollback_reports', self::ROLLBACK_COLUMNS, encrypt: true);
    }

    public function down(): void
    {
        $this->transform('import_sessions', self::SESSION_COLUMNS, encrypt: false);
        $this->transform('import_rollback_reports', self::ROLLBACK_COLUMNS, encrypt: false);

        Schema::table('import_sessions', function (Blueprint $table): void {
            $table->string('target_url')->nullable()->change();
            $table->string('source_package_path')->nullable()->change();
            $table->string('working_dir')->nullable()->change();
            $table->json('manifest')->nullable()->change();
            $table->json('resolution_map')->nullable()->change();
            $table->json('page_decisions')->nullable()->change();
            $table->json('relation_decisions')->nullable()->change();
            $table->json('validation_results')->nullable()->change();
            $table->json('result_summary')->nullable()->change();
            $table->text('failure_reason')->nullable()->change();
        });
        Schema::table('import_rollback_reports', function (Blueprint $table): void {
            $table->string('source_filename')->nullable()->change();
            $table->json('created_models')->nullable()->change();
            $table->json('summary')->nullable()->change();
            $table->text('manual_instructions')->change();
        });
    }

    /** @param list<string> $columns */
    private function transform(string $table, array $columns, bool $encrypt): void
    {
        DB::table($table)->orderBy('id')->each(function (object $record) use ($table, $columns, $encrypt): void {
            $values = [];

            foreach ($columns as $column) {
                $value = $record->{$column};
                $values[$column] = is_string($value)
                    ? ($encrypt ? Crypt::encryptString($value) : Crypt::decryptString($value))
                    : null;
            }

            DB::table($table)->where('id', $record->id)->update($values);
        });
    }
};
