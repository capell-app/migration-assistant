<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Actions\ContentGraph\PruneContentGraphEdgesAction;
use Capell\Core\Actions\ContentGraph\RebuildContentGraphForModelAction;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\MigrationAssistant\Data\DatabaseImportWritePlan;
use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Capell\MigrationAssistant\Support\DatabaseImportWriteGuard;
use Illuminate\Database\Eloquent\Model;

/** Rebuild derived edges after restoring content; user rows are never rollback-owned targets. */
final class FinalisePlannedContentGraphAction
{
    public function handle(DatabaseImportWritePlan $plan, Model $model): void
    {
        if ($plan->executionMode !== 'dedicated-process' || ! isset($plan->targets[ContentGraphEdge::class])) {
            return;
        }

        $target = $model instanceof Translation ? $model->translatable : $model;
        $article = config('migration-assistant.rollback_targets.article');
        if (! $target instanceof Page && (! is_string($article) || ! $target instanceof $article)) {
            return;
        }

        $key = $target->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw InPlaceDatabaseRefusalReason::Target->exception('Content graph finalisation requires a scalar identity.');
        }

        // Outgoing derived edges must not make a newly-created content row look externally owned.
        PruneContentGraphEdgesAction::run($target);
        DatabaseImportWriteGuard::deferWithin('rollback-content-graph:' . $target::class . ':' . $key, static function () use ($target): void {
            $fresh = $target->newQueryWithoutScopes()->find($target->getKey());
            if ($fresh instanceof Model && (! method_exists($fresh, 'trashed') || ! $fresh->trashed())) {
                RebuildContentGraphForModelAction::run($fresh);
            }
        });
    }
}
