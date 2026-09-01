<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Services\Import\ResolutionMapBuilder;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolver;

function makeResolver(?MatchResolution $resolution): MatchResolver
{
    return new readonly class($resolution) implements MatchResolver
    {
        public function __construct(private ?MatchResolution $resolution) {}

        public function resolve(array $descriptor, ?int $siteId = null): ?MatchResolution
        {
            return $this->resolution;
        }
    };
}

final class ResolutionMapBuilderSiteIdCapture
{
    /** @var list<int|null> */
    public array $calls = [];
}

function makeSiteIdCapturingResolver(?MatchResolution $resolution, ResolutionMapBuilderSiteIdCapture $capture): MatchResolver
{
    return new class($resolution, $capture) implements MatchResolver
    {
        public function __construct(
            private ?MatchResolution $resolution,
            private ResolutionMapBuilderSiteIdCapture $capture,
        ) {}

        /**
         * @param  array<string, mixed>  $descriptor
         */
        public function resolve(array $descriptor, ?int $siteId = null): ?MatchResolution
        {
            $this->capture->calls[] = $siteId;

            return $this->resolution;
        }
    };
}

function makeSiteEchoResolver(): MatchResolver
{
    return new class implements MatchResolver
    {
        public function resolve(array $descriptor, ?int $siteId = null): MatchResolution
        {
            $ref = is_string($descriptor['ref'] ?? null) ? $descriptor['ref'] : '';
            $id = (int) str_replace('site:', '', $ref);

            return new MatchResolution(localId: $id, strategy: 'id');
        }
    };
}

/**
 * @param  array<string, mixed>  $payload
 */
function migrationAssistantResolutionPayload(array $payload): string
{
    return json_encode($payload, JSON_THROW_ON_ERROR);
}

it('resolves refs that a matching resolver handles', function (): void {
    $builder = new ResolutionMapBuilder([
        'layouts' => makeResolver(new MatchResolution(localId: 42, strategy: 'key')),
    ]);

    $map = $builder->build([
        'relations/layouts/abc.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1', 'key' => 'home']),
    ]);

    expect($map->hasUnresolved())->toBeFalse()
        ->and($map->localIdFor('layout:1'))->toBe(42);
});

it('records refs with no matching resolver as unresolved', function (): void {
    $builder = new ResolutionMapBuilder(resolvers: []);

    $map = $builder->build([
        'relations/layouts/abc.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1']),
    ]);

    expect($map->hasUnresolved())->toBeTrue()
        ->and($map->unresolved)->toBe(['layout:1']);
});

it('records refs the resolver rejects as unresolved', function (): void {
    $builder = new ResolutionMapBuilder([
        'layouts' => makeResolver(null),
    ]);

    $map = $builder->build([
        'relations/layouts/abc.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1']),
    ]);

    expect($map->unresolved)->toBe(['layout:1']);
});

it('skips non-relation entries', function (): void {
    $builder = new ResolutionMapBuilder([
        'layouts' => makeResolver(new MatchResolution(localId: 1, strategy: 'key')),
    ]);

    $map = $builder->build([
        'pages/p.json' => migrationAssistantResolutionPayload(['type' => 'page']),
    ]);

    expect($map->resolved)->toBe([])
        ->and($map->unresolved)->toBe([]);
});

it('resolves the sites group first and threads matched site ids into every other group', function (): void {
    $capture = new ResolutionMapBuilderSiteIdCapture;

    $builder = new ResolutionMapBuilder([
        'sites' => makeResolver(new MatchResolution(localId: 7, strategy: 'slug')),
        'layouts' => makeSiteIdCapturingResolver(new MatchResolution(localId: 42, strategy: 'key'), $capture),
    ]);

    $map = $builder->build([
        'relations/sites/a.json' => migrationAssistantResolutionPayload(['type' => 'site', 'ref' => 'site:1', 'id' => 1]),
        'relations/layouts/b.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1', 'attributes' => ['site_id' => 1]]),
    ]);

    expect($map->localIdFor('layout:1'))->toBe(42)
        ->and($capture->calls)->toBe([7]);
});

it('threads no target site id when no sites resolve', function (): void {
    $capture = new ResolutionMapBuilderSiteIdCapture;

    $builder = new ResolutionMapBuilder([
        'sites' => makeResolver(null),
        'layouts' => makeSiteIdCapturingResolver(new MatchResolution(localId: 42, strategy: 'key'), $capture),
    ]);

    $map = $builder->build([
        'relations/sites/a.json' => migrationAssistantResolutionPayload(['type' => 'site', 'ref' => 'site:1', 'id' => 1]),
        'relations/layouts/b.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1', 'attributes' => ['site_id' => 1]]),
    ]);

    expect($map->localIdFor('layout:1'))->toBe(42)
        ->and($capture->calls)->toBe([null]);
});

it('threads each relation its own resolved site id regardless of payload order', function (): void {
    $capture = new ResolutionMapBuilderSiteIdCapture;

    $builder = new ResolutionMapBuilder([
        'sites' => makeSiteEchoResolver(),
        'layouts' => makeSiteIdCapturingResolver(new MatchResolution(localId: 1, strategy: 'key'), $capture),
    ]);

    $builder->build([
        'relations/layouts/c.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:1', 'attributes' => ['site_id' => 5]]),
        'relations/layouts/d.json' => migrationAssistantResolutionPayload(['type' => 'layout', 'ref' => 'layout:2', 'attributes' => ['site_id' => 9]]),
        'relations/sites/a.json' => migrationAssistantResolutionPayload(['type' => 'site', 'ref' => 'site:5', 'id' => 5]),
        'relations/sites/b.json' => migrationAssistantResolutionPayload(['type' => 'site', 'ref' => 'site:9', 'id' => 9]),
    ]);

    expect($capture->calls)->toEqual([5, 9]);
});
