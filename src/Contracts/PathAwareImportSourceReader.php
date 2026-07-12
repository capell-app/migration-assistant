<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

interface PathAwareImportSourceReader extends ImportSourceReader
{
    public function supportsPath(string $path): bool;
}
