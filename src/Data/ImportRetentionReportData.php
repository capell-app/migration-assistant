<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Spatie\LaravelData\Data;

final class ImportRetentionReportData extends Data
{
    /** @param list<int> $eligibleSessionIds
     * @param  list<int>  $failedSessionIds
     */
    public function __construct(
        public int $retentionDays,
        public bool $dryRun,
        public int $sessions = 0,
        public int $archiveBytes = 0,
        public int $eligibleBytes = 0,
        public array $eligibleSessionIds = [],
        public int $pruned = 0,
        public array $failedSessionIds = [],
    ) {}
}
