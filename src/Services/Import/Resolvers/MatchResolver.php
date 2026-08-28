<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import\Resolvers;

/**
 * Given a shared-relation descriptor from an incoming package, return
 * the ID of the best matching local record or null if none was found.
 *
 * Resolvers walk a strict preference order (uuid → key/slug → normalised
 * name → fingerprint) so that round-trip imports stay stable across
 * environments even when primary keys drift.
 */
interface MatchResolver
{
    /**
     * @param  array<string, mixed>  $descriptor  decoded relations/<folder>/<key>.json
     * @param  list<int>  $siteIds  local site IDs legitimately in play for this import (the
     *                              import's own resolved `sites` shared relations); empty when
     *                              none resolved yet or the group isn't site-scoped. A resolver
     *                              that matches a tenant-scoped model should use this to exclude
     *                              records belonging to a site outside this set.
     */
    public function resolve(array $descriptor, array $siteIds = []): ?MatchResolution;
}
