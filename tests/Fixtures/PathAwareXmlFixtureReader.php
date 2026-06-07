<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Fixtures;

use Capell\MigrationAssistant\Contracts\PathAwareImportSourceReader;
use Capell\MigrationAssistant\Data\ExternalImportReadResult;

final class PathAwareXmlFixtureReader implements PathAwareImportSourceReader
{
    public function supports(string $extension): bool
    {
        return strtolower($extension) === 'xml';
    }

    public function supportsPath(string $path): bool
    {
        return str_contains(file_get_contents($path) ?: '', '<fixture />');
    }

    public function read(string $path): ExternalImportReadResult
    {
        return new ExternalImportReadResult(
            sourceType: 'fixture-xml',
            columns: ['title'],
            rows: [['title' => basename($path)]],
        );
    }
}
