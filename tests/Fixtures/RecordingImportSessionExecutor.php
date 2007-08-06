<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Fixtures;

use Capell\MigrationAssistant\Contracts\ImportSessionExecutor;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use RuntimeException;

final class RecordingImportSessionExecutor implements ImportSessionExecutor
{
    public int $executions = 0;

    public bool $failAfterCompletion = false;

    public function supports(ImportSession $session): bool
    {
        return $session->source_environment === 'handoff-test';
    }

    public function canRetry(ImportSession $session): bool
    {
        return true;
    }

    public function execute(ImportSession $session): void
    {
        $this->executions++;
        $session->forceFill(['status' => ImportSessionStatus::Completed])->save();

        if ($this->failAfterCompletion) {
            throw new RuntimeException('Completion callback failed');
        }
    }
}
