<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Spatie\LaravelData\Data;

final class PageImportTargetData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly int|string|null $id = null,
        public readonly ?string $label = null,
        public readonly ?string $url = null,
        public readonly ?int $legacyWorkspaceId = null,
    ) {}
}
