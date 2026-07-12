<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Spatie\LaravelData\Data;

final class RollbackExecutionResultData extends Data
{
    /**
     * @param  list<array{type: string, id: int|string, reason: string}>  $skipped
     */
    public function __construct(
        public int $matched,
        public int $deleted,
        public array $skipped,
        public bool $dryRun,
        public bool $rejected = false,
    ) {}
}
