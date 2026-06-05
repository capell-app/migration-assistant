<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data\Imports;

use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\ImportExecutionReport;

final readonly class ExternalPageImportExecutionResult
{
    public function __construct(
        public ImportSession $session,
        public ImportExecutionReport $report,
    ) {}
}
