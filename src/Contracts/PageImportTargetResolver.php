<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Capell\MigrationAssistant\Data\PageImportTargetData;
use Capell\MigrationAssistant\Models\ImportSession;

interface PageImportTargetResolver
{
    public function create(string $name): PageImportTargetData;

    public function resolve(ImportSession $session): PageImportTargetData;
}
