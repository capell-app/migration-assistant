<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Contracts\ImportSessionExecutor;
use Capell\MigrationAssistant\Models\ImportSession;

final class ImportSessionExecutorRegistry
{
    /** @var list<ImportSessionExecutor> */
    private array $executors = [];

    public function register(ImportSessionExecutor $executor, bool $prepend = false): void
    {
        if ($prepend) {
            array_unshift($this->executors, $executor);

            return;
        }

        $this->executors[] = $executor;
    }

    public function executorFor(ImportSession $session): ?ImportSessionExecutor
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($session)) {
                return $executor;
            }
        }

        return null;
    }
}
