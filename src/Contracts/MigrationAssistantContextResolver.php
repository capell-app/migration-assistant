<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Closure;

interface MigrationAssistantContextResolver
{
    /**
     * Execute $callback inside any ambient scope the resolver manages.
     * The resolver decides what state to set up before the callback and
     * tears it down after.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function wrap(Closure $callback, ?int $sourceWorkspaceId = null): mixed;

    /**
     * @param  array<int, int|string>  $pageIds
     * @return array<int, int|string>
     */
    public function resolvePageIds(array $pageIds, ?int $sourceWorkspaceId = null): array;
}
