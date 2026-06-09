<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * @method static RollbackExecutionResultData run(ImportRollbackReport $report, bool $dryRun = false)
 */
final class ExecuteImportRollbackAction
{
    use AsAction;

    public function handle(ImportRollbackReport $report, bool $dryRun = false): RollbackExecutionResultData
    {
        $createdModels = array_reverse($this->createdModels($report));
        $matched = 0;
        $deleted = 0;
        $skipped = [];

        DB::transaction(function () use ($createdModels, $report, $dryRun, &$matched, &$deleted, &$skipped): void {
            foreach ($createdModels as $createdModel) {
                $model = $this->findModel($createdModel);

                if (! $model instanceof Model) {
                    $skipped[] = $this->skip($createdModel, 'missing');

                    continue;
                }

                $matched++;

                if ($this->wasEditedAfterImport($model, $report)) {
                    $skipped[] = $this->skip($createdModel, 'edited_after_import');

                    continue;
                }

                if (! $dryRun) {
                    $model->delete();
                    $deleted++;
                }
            }

            if (! $dryRun) {
                $summary = is_array($report->summary) ? $report->summary : [];
                $summary['rollback_execution'] = [
                    'deleted' => $deleted,
                    'executed_at' => now()->toISOString(),
                    'matched' => $matched,
                    'skipped' => $skipped,
                ];

                $report->forceFill(['summary' => $summary])->save();
            }
        });

        return new RollbackExecutionResultData(
            matched: $matched,
            deleted: $deleted,
            skipped: $skipped,
            dryRun: $dryRun,
        );
    }

    /**
     * @return list<array{class: string, id: int|string}>
     */
    private function createdModels(ImportRollbackReport $report): array
    {
        $createdModels = is_array($report->created_models) ? $report->created_models : [];

        return array_values(array_filter(
            $createdModels,
            static fn (mixed $entry): bool => is_array($entry)
                && is_string($entry['class'] ?? null)
                && (is_int($entry['id'] ?? null) || is_string($entry['id'] ?? null)),
        ));
    }

    /**
     * @param  array{class: string, id: int|string}  $createdModel
     */
    private function findModel(array $createdModel): ?Model
    {
        $class = $createdModel['class'];

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($createdModel['id']);
    }

    private function wasEditedAfterImport(Model $model, ImportRollbackReport $report): bool
    {
        $executedAt = $this->dateAttribute($report, 'executed_at');
        $updatedAtColumn = $model->getUpdatedAtColumn();
        $createdAtColumn = $model->getCreatedAtColumn();
        $updatedAt = (is_string($updatedAtColumn) ? $this->dateAttribute($model, $updatedAtColumn) : null)
            ?? (is_string($createdAtColumn) ? $this->dateAttribute($model, $createdAtColumn) : null);

        if (! $executedAt instanceof CarbonInterface) {
            return false;
        }

        if (! $updatedAt instanceof CarbonInterface) {
            return $executedAt->lessThan(now());
        }

        return $updatedAt->greaterThan($executedAt);
    }

    private function dateAttribute(Model $model, string $attribute): ?CarbonInterface
    {
        $value = $model->getAttribute($attribute);

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array{class: string, id: int|string}  $createdModel
     * @return array{class: string, id: int|string, reason: string}
     */
    private function skip(array $createdModel, string $reason): array
    {
        return [
            'class' => $createdModel['class'],
            'id' => $createdModel['id'],
            'reason' => $reason,
        ];
    }
}
