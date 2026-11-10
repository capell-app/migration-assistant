<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Models;

use Capell\Core\Concerns\HasCapellTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Override;

/**
 * @property int $id
 * @property int $import_rollback_report_id
 * @property int|null $actor_id
 * @property bool $dry_run
 * @property string $outcome
 * @property int $matched
 * @property int $restored
 * @property int $deleted
 * @property array<int, array{type: string, id: int|string, reason: string}> $skipped
 * @property CarbonImmutable|null $created_at
 * @property-read ImportRollbackReport $report
 * @property-read User|null $actor
 */
class ImportRollbackAudit extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'import_rollback_report_id',
        'actor_id',
        'dry_run',
        'outcome',
        'matched',
        'deleted',
        'restored',
        'skipped',
    ];

    /**
     * @return BelongsTo<ImportRollbackReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ImportRollbackReport::class, 'import_rollback_report_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'skipped' => 'array',
        ];
    }
}
