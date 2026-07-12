<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Spatie\LaravelData\Data;

final class ImportRecoveryStatusData extends Data
{
    public function __construct(
        public int $staleCount,
        public ?int $oldestAgeMinutes,
    ) {}
}
