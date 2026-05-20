<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Capell\MigrationAssistant\Data\PageImportTargetData;
use Capell\MigrationAssistant\Models\ImportSession;

final class NullPageImportTargetResolver implements PageImportTargetResolver
{
    public function create(string $name): PageImportTargetData
    {
        return new PageImportTargetData(
            type: 'live',
            label: $name !== '' ? $name : null,
        );
    }

    public function resolve(ImportSession $session): PageImportTargetData
    {
        $targetType = $session->target_type;
        $targetId = $session->target_id;

        return new PageImportTargetData(
            type: is_string($targetType) && $targetType !== '' ? $targetType : 'live',
            id: is_int($targetId) || is_string($targetId) ? $targetId : null,
            label: is_string($session->target_label) && $session->target_label !== '' ? $session->target_label : null,
            url: is_string($session->target_url) && $session->target_url !== '' ? $session->target_url : null,
        );
    }
}
