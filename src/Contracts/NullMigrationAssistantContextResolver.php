<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Closure;

final class NullMigrationAssistantContextResolver implements MigrationAssistantContextResolver
{
    public function wrap(Closure $callback, ?int $sourceWorkspaceId = null): mixed
    {
        return $callback();
    }

    public function resolvePageIds(array $pageIds, ?int $sourceWorkspaceId = null): array
    {
        return $pageIds;
    }
}
