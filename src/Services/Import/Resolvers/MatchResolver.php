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
     * @param  int|null  $siteId  local target site ID for this relation, resolved from the
     *                            relation's source site; null when no site context is available.
     *                            A resolver that matches a tenant-scoped model should use this to
     *                            exclude records belonging to another site.
     */
    public function resolve(array $descriptor, ?int $siteId = null): ?MatchResolution;
}
