<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

interface SafeDatabaseFailureContext
{
    /** @return array{entity: string, source_id: string} */
    public function safeFailureContext(): array;
}
