<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Models;

use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Userstampable;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $workspace_id
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $target_label
 * @property string|null $target_url
 * @property ImportSessionKind $kind
 * @property ImportSessionStatus $status
 * @property string|null $source_environment
 * @property string|null $source_filename
 * @property string|null $source_package_path
 * @property string|null $source_package_checksum
 * @property string|null $working_dir
 * @property array<array-key, mixed>|null $manifest
 * @property array<array-key, mixed>|null $resolution_map
 * @property array<array-key, mixed>|null $page_decisions
 * @property array<array-key, mixed>|null $relation_decisions
 * @property array<array-key, mixed>|null $validation_results
 * @property array<array-key, mixed>|null $result_summary
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $executed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, ImportRollbackReport> $rollbackReports
 */
class ImportSession extends Model implements Userstampable
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUserstamps;
    use HasUuids;

    protected $table = 'import_sessions';

    protected $fillable = [
        'uuid',
        'user_id',
        'target_type',
        'target_id',
        'target_label',
        'target_url',
        'kind',
        'status',
        'source_environment',
        'source_filename',
        'source_package_path',
        'source_package_checksum',
        'working_dir',
        'manifest',
        'resolution_map',
        'page_decisions',
        'relation_decisions',
        'validation_results',
        'result_summary',
        'failure_reason',
        'reviewed_at',
        'resolved_at',
        'validated_at',
        'executed_at',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    /** @return array<int, string> */
    #[Override]
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRollbackReport, $this>
     */
    public function rollbackReports(): HasMany
    {
        return $this->hasMany(ImportRollbackReport::class);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'kind' => ImportSessionKind::class,
            'status' => ImportSessionStatus::class,
            'target_id' => 'integer',
            'target_url' => 'encrypted',
            'source_package_path' => 'encrypted',
            'working_dir' => 'encrypted',
            'manifest' => 'encrypted:array',
            'resolution_map' => 'encrypted:array',
            'page_decisions' => 'encrypted:array',
            'relation_decisions' => 'encrypted:array',
            'validation_results' => 'encrypted:array',
            'result_summary' => 'encrypted:array',
            'failure_reason' => 'encrypted',
            'reviewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
        ];
    }
}
