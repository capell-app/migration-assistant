<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

final readonly class SourceKeyImportResult
{
    /** @param array<string, mixed>|null $beforeImage
     * @param  array<string, mixed>|null  $mapping
     */
    public function __construct(
        public string $outcome,
        public int|string $targetId,
        public ?array $beforeImage = null,
        public ?array $mapping = null,
    ) {}
}
