<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\Imports\PageImportStatusData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static PageImportStatusData run(?int $sessionId, ?int $currentTargetId)
 */
final class RefreshPageImportStatusAction
{
    use AsAction;

    public function handle(?int $sessionId, ?int $currentTargetId): PageImportStatusData
    {
        if ($sessionId === null) {
            return new PageImportStatusData(step: 'executing');
        }

        $session = ResolvePageImportSessionAction::run($sessionId);
        if (! $session instanceof ImportSession) {
            return new PageImportStatusData(step: 'executing');
        }

        $status = $session->status;
        $target = resolve(PageImportTargetResolver::class)->resolve($session);
        $targetId = is_int($target->id) ? $target->id : $currentTargetId;
        $resultSummary = is_array($session->result_summary) ? $session->result_summary : [];

        if ($status === ImportSessionStatus::Completed) {
            return new PageImportStatusData(
                step: 'completed',
                sessionStatus: $status->value,
                resultSummary: $resultSummary,
                targetId: $targetId,
                targetUrl: $target->url,
            );
        }

        if ($status === ImportSessionStatus::Failed) {
            return new PageImportStatusData(
                step: 'failed',
                sessionStatus: $status->value,
                resultSummary: $resultSummary,
                failureReason: $session->failure_reason,
                targetId: $targetId,
                targetUrl: $target->url,
            );
        }

        return new PageImportStatusData(
            step: 'executing',
            sessionStatus: $status->value,
            resultSummary: $resultSummary,
            targetId: $targetId,
            targetUrl: $target->url,
        );
    }
}
