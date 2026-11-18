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
        public int $restored = 0,
    ) {}

    public function hasFailures(): bool
    {
        return $this->rejected || array_any($this->skipped, static fn (array $entry): bool => $entry['reason'] === 'delete_failed');
    }
}
