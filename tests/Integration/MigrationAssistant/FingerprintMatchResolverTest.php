<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Services\Import\Resolvers\FingerprintMatchResolver;
use Illuminate\Support\Facades\DB;

it('matches a layout with an identical normalised schema', function (): void {
    $layout = Layout::factory()->create([
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'id' => 999,
            'created_at' => '2020-01-01',
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    $match = $resolver->resolve($descriptor);
    $match = migrationAssistantMatchResolution($match);

    expect($match)->not->toBeNull()
        ->and($match->localId)->toBe($layout->getKey())
        ->and($match->strategy)->toBe('fingerprint')
        ->and($match->confidence)->toBe(0.7);
});

it('ignores key order inside the schema JSON when fingerprinting', function (): void {
    $layout = Layout::factory()->create([
        'admin' => ['alpha' => 1, 'beta' => 2],
        'meta' => ['x' => 'y'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class);

    $descriptor = [
        'ref' => 'layout:42',
        'attributes' => [
            'admin' => ['beta' => 2, 'alpha' => 1],
            'meta' => ['x' => 'y'],
        ],
    ];

    expect($resolver->resolve($descriptor)?->localId)->toBe($layout->getKey());
});

it('returns null when no local layout matches the fingerprint', function (): void {
    Layout::factory()->create([
        'admin' => ['fields' => [['name' => 'title']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class);

    $descriptor = [
        'ref' => 'layout:1',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'totally_different']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    expect($resolver->resolve($descriptor))->toBeNull();
});

it('returns null when the descriptor carries no schema content', function (): void {
    Layout::factory()->create(['admin' => ['fields' => []], 'meta' => null]);

    $resolver = new FingerprintMatchResolver(Layout::class);

    expect($resolver->resolve(['ref' => 'layout:1', 'attributes' => ['admin' => null, 'meta' => null]]))
        ->toBeNull();
});

it('streams only the fingerprint columns when scanning local candidates', function (): void {
    Layout::factory()->create([
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    DB::enableQueryLog();

    $resolver = new FingerprintMatchResolver(Layout::class);
    $resolver->resolve([
        'ref' => 'layout:999',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ]);

    $candidateQuery = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $query): bool => str_contains($query, 'layouts'));

    expect($candidateQuery)->not->toBeNull()
        ->and(strtolower((string) $candidateQuery))->not->toContain('select *');
});

it('matches a global layout by fingerprint even when scoped to specific sites', function (): void {
    $layout = Layout::factory()->create([
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class, scopeToSite: true);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    expect($resolver->resolve($descriptor, 9999999)?->localId)->toBe($layout->getKey());
});

it('does not match a private layout belonging to a different site when scoped to site', function (): void {
    $authorisedSite = Site::factory()->create();
    $victimSite = Site::factory()->create();

    Layout::factory()->create([
        'site_id' => $victimSite->getKey(),
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class, scopeToSite: true);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    expect($resolver->resolve($descriptor, $authorisedSite->getKey()))->toBeNull();
});

it('matches a private layout belonging to an authorised site when scoped to site', function (): void {
    $authorisedSite = Site::factory()->create();

    $layout = Layout::factory()->create([
        'site_id' => $authorisedSite->getKey(),
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class, scopeToSite: true);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    expect($resolver->resolve($descriptor, $authorisedSite->getKey())?->localId)->toBe($layout->getKey());
});

it('only matches a private layout within the target site when resolving a fingerprint', function (): void {
    $targetSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $schema = [
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ];

    $foreignLayout = Layout::factory()->create([
        'site_id' => $otherSite->getKey(),
        ...$schema,
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class, scopeToSite: true);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'site_id' => $targetSite->getKey(),
            ...$schema,
        ],
    ];

    expect($resolver->resolve($descriptor, $targetSite->getKey()))->toBeNull();

    $targetLayout = Layout::factory()->create([
        'site_id' => $targetSite->getKey(),
        ...$schema,
    ]);

    expect($resolver->resolve($descriptor, $targetSite->getKey())?->localId)
        ->toBe($targetLayout->getKey())
        ->not->toBe($foreignLayout->getKey());
});

it('remains fully unscoped by default, matching a private layout with no site context', function (): void {
    $victimSite = Site::factory()->create();

    $layout = Layout::factory()->create([
        'site_id' => $victimSite->getKey(),
        'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
        'meta' => ['cache_time' => 'hour'],
    ]);

    $resolver = new FingerprintMatchResolver(Layout::class);

    $descriptor = [
        'ref' => 'layout:999',
        'attributes' => [
            'admin' => ['fields' => [['name' => 'title', 'type' => 'text']]],
            'meta' => ['cache_time' => 'hour'],
        ],
    ];

    expect($resolver->resolve($descriptor)?->localId)->toBe($layout->getKey());
});
