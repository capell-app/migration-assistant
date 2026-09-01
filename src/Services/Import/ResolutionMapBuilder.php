<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import;

use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolver;
use Capell\MigrationAssistant\Services\Import\Resolvers\RelationMatchResolverRegistry;
use RuntimeException;

/**
 * Walks the shared-relation descriptors in a read package payload and asks
 * each registered resolver to find a local match. Returns a ResolutionMap
 * describing every ref plus the list of refs that still need a human.
 *
 * Accepts either a RelationMatchResolverRegistry (preferred; lets packages
 * extend the priority chain) or the legacy array<group, MatchResolver>
 * shape for BC with existing call sites and tests.
 */
final readonly class ResolutionMapBuilder
{
    private const string SITES_GROUP = 'sites';

    private RelationMatchResolverRegistry $registry;

    /**
     * @param  RelationMatchResolverRegistry|array<string, MatchResolver|list<MatchResolver>>  $resolvers
     */
    public function __construct(RelationMatchResolverRegistry|array $resolvers)
    {
        $this->registry = $resolvers instanceof RelationMatchResolverRegistry
            ? $resolvers
            : new RelationMatchResolverRegistry($resolvers);
    }

    /**
     * @param  array<string, string>  $payload  archive-path => JSON contents
     */
    public function build(array $payload): ResolutionMap
    {
        $siteIdsBySourceId = $this->resolveSiteIdMap($payload);

        $resolved = [];
        $unresolved = [];

        foreach ($payload as $entryPath => $contents) {
            if (! str_starts_with($entryPath, 'relations/')) {
                continue;
            }

            $parts = explode('/', $entryPath, 3);
            if (count($parts) < 3) {
                continue;
            }

            $folder = $parts[1];

            $descriptor = $this->decode($contents, $entryPath);
            $ref = is_string($descriptor['ref'] ?? null) ? $descriptor['ref'] : null;
            if ($ref === null) {
                continue;
            }

            if (! $this->registry->hasGroup($folder)) {
                $unresolved[] = $ref;

                continue;
            }

            $siteId = $this->targetSiteId($descriptor, $siteIdsBySourceId);
            $resolution = $this->registry->resolve($folder, $descriptor, $siteId);
            if (! $resolution instanceof MatchResolution) {
                $unresolved[] = $ref;

                continue;
            }

            $resolved[$ref] = $resolution;
        }

        return new ResolutionMap(resolved: $resolved, unresolved: $unresolved);
    }

    /**
     * Resolve the archive's own `sites` shared relations before anything
     * else, so that other groups (e.g. layouts) can restrict their matches
     * to the site legitimately owning each relation. Without this, a
     * site-scoped resolver would receive every site in a multi-site import
     * and could bind one site's relation to another site's matching shape.
     *
     * @param  array<string, string>  $payload
     * @return array<int, int> source site ID => local site ID
     */
    private function resolveSiteIdMap(array $payload): array
    {
        if (! $this->registry->hasGroup(self::SITES_GROUP)) {
            return [];
        }

        $prefix = 'relations/' . self::SITES_GROUP . '/';
        $siteIdsBySourceId = [];

        foreach ($payload as $entryPath => $contents) {
            if (! str_starts_with($entryPath, $prefix)) {
                continue;
            }

            $descriptor = $this->decode($contents, $entryPath);
            $resolution = $this->registry->resolve(self::SITES_GROUP, $descriptor);

            $sourceSiteId = $this->integerAttribute($descriptor['id'] ?? null);
            if ($sourceSiteId !== null && $resolution instanceof MatchResolution && is_int($resolution->localId)) {
                $siteIdsBySourceId[$sourceSiteId] = $resolution->localId;
            }
        }

        return $siteIdsBySourceId;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  array<int, int>  $siteIdsBySourceId
     */
    private function targetSiteId(array $descriptor, array $siteIdsBySourceId): ?int
    {
        $attributes = $descriptor['attributes'] ?? null;
        if (! is_array($attributes)) {
            return null;
        }

        $sourceSiteId = $this->integerAttribute($attributes['site_id'] ?? null);

        return $sourceSiteId === null ? null : ($siteIdsBySourceId[$sourceSiteId] ?? null);
    }

    private function integerAttribute(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents, string $entryPath): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if ($decoded === []) {
            throw new RuntimeException(sprintf('Empty descriptor for [%s].', $entryPath));
        }

        return $decoded;
    }
}
