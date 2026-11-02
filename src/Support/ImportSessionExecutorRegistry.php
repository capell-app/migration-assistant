<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Contracts\DatabaseImportSessionExecutor;
use Capell\MigrationAssistant\Contracts\ImportSessionExecutor;
use Capell\MigrationAssistant\Models\ImportSession;
use LogicException;

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
                if (is_array($session->manifest) && ($session->manifest['type'] ?? null) === 'database' && ! $executor instanceof DatabaseImportSessionExecutor) {
                    throw new LogicException('Database sources require a guarded database executor.');
                }

                return $executor;
            }
        }

        return null;
    }
}
