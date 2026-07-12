<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Contracts\ImportSourceReader;
use Capell\MigrationAssistant\Contracts\PathAwareImportSourceReader;
use RuntimeException;

final class ImportSourceRegistry
{
    /** @var list<ImportSourceReader> */
    private array $readers = [];

    public function register(ImportSourceReader $reader, bool $prepend = false): void
    {
        if ($prepend) {
            array_unshift($this->readers, $reader);

            return;
        }

        $this->readers[] = $reader;
    }

    public function readerFor(string $path): ImportSourceReader
    {
        $hasReadablePath = is_readable($path) && ! is_dir($path);

        if ($hasReadablePath) {
            foreach ($this->readers as $reader) {
                if ($reader instanceof PathAwareImportSourceReader && $reader->supportsPath($path)) {
                    return $reader;
                }
            }
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach ($this->readers as $reader) {
            if ($hasReadablePath && $reader instanceof PathAwareImportSourceReader) {
                continue;
            }

            if ($reader->supports($extension)) {
                return $reader;
            }
        }

        throw new RuntimeException(sprintf('No import source reader is registered for [%s].', $extension));
    }

    /**
     * @return list<ImportSourceReader>
     */
    public function readers(): array
    {
        return $this->readers;
    }
}
