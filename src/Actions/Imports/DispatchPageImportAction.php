<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions\Imports;

use Capell\MigrationAssistant\Contracts\PageImportTargetResolver;
use Capell\MigrationAssistant\Data\Imports\PageImportStatusData;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static PageImportStatusData run(?int $sessionId, array $validationSummary, string $confirmation, string $confirmationExpected)
 */
final class DispatchPageImportAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $validationSummary
     */
    public function handle(
        ?int $sessionId,
        array $validationSummary,
        string $confirmation,
        string $confirmationExpected,
    ): PageImportStatusData {
        if ($sessionId === null) {
            return new PageImportStatusData(step: 'validate');
        }

        $session = ResolvePageImportSessionAction::run($sessionId);
        if (! $session instanceof ImportSession) {
            return new PageImportStatusData(step: 'validate');
        }

        $storedValidationSummary = is_array($session->validation_results)
            ? $session->validation_results
            : [];

        $blockingErrors = $storedValidationSummary['blocking_errors'] ?? [];
        if (is_array($blockingErrors) && $blockingErrors !== []) {
            return new PageImportStatusData(
                step: 'validate',
                notice: PageImportStatusData::NOTICE_SUMMARY_BLOCKING_ERRORS,
                noticeBody: implode(' / ', array_filter(
                    $blockingErrors,
                    is_string(...),
                )),
            );
        }

        $expectedConfirmation = ResolvePageImportConfirmationTargetAction::run($session);

        if (! $this->confirmationMatches($confirmation, $expectedConfirmation)) {
            return new PageImportStatusData(
                step: 'validate',
                notice: PageImportStatusData::NOTICE_CONFIRMATION_MISMATCH,
            );
        }

        $session->forceFill([
            'status' => ImportSessionStatus::Queued,
        ])->save();

        dispatch(new ExecuteImportPlanJob((int) $session->getKey()));

        $target = resolve(PageImportTargetResolver::class)->resolve($session);

        return new PageImportStatusData(
            step: 'executing',
            sessionStatus: ImportSessionStatus::Queued->value,
            targetId: is_int($target->id) ? $target->id : null,
            targetUrl: $target->url,
            notice: PageImportStatusData::NOTICE_IMPORT_QUEUED,
        );
    }

    private function confirmationMatches(string $confirmation, string $confirmationExpected): bool
    {
        if ($confirmationExpected === '') {
            return true;
        }

        return mb_strtolower(trim($confirmation)) === mb_strtolower(trim($confirmationExpected));
    }
}
