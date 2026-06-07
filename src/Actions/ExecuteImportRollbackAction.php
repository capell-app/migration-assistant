<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\MigrationAssistant\Data\RollbackExecutionResultData;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

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
        $executedAt = $report->executed_at;
        $updatedAt = $model->getAttribute('updated_at');

        if ($executedAt === null || ! method_exists($updatedAt, 'greaterThan')) {
            return false;
        }

        return $updatedAt->greaterThan($executedAt);
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
