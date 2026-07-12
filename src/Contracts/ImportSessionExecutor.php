<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Capell\MigrationAssistant\Models\ImportSession;

interface ImportSessionExecutor
{
    public function supports(ImportSession $session): bool;

    public function canRetry(ImportSession $session): bool;

    public function execute(ImportSession $session): void;
}
