<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Spatie\LaravelData\Data;

/**
 * The fixed local destination for every row in an external page import.
 *
 * Values in an external source are never allowed to override this target.
 */
final class ExternalPageImportTargetData extends Data
{
    public function __construct(
        public readonly int $siteId,
        public readonly int $layoutId,
        public readonly int $blueprintId,
        public readonly int $languageId,
    ) {}

    /**
     * @return array{site_id: int, layout_id: int, blueprint_id: int, language_id: int}
     */
    public function pageAttributes(): array
    {
        return [
            'site_id' => $this->siteId,
            'layout_id' => $this->layoutId,
            'blueprint_id' => $this->blueprintId,
            'language_id' => $this->languageId,
        ];
    }
}
