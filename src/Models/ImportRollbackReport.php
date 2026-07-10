<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $import_session_id
 * @property int|null $user_id
 * @property string|null $source_filename
 * @property string|null $source_package_checksum
 * @property array<int, array{class: string, id: int|string}>|null $created_models
 * @property array<array-key, mixed>|null $summary
 * @property string $manual_instructions
 * @property CarbonImmutable|null $executed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ImportSession $importSession
 */
class ImportRollbackReport extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUuids;

    protected $table = 'import_rollback_reports';

    protected $fillable = [
        'uuid',
        'import_session_id',
        'user_id',
        'source_filename',
        'source_package_checksum',
        'created_models',
        'summary',
        'manual_instructions',
        'executed_at',
    ];

    /** @return array<int, string> */
    #[Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<ImportSession, $this>
     */
    public function importSession(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'source_filename' => 'encrypted',
            'created_models' => 'encrypted:array',
            'summary' => 'encrypted:array',
            'manual_instructions' => 'encrypted',
            'executed_at' => 'immutable_datetime',
        ];
    }
}
