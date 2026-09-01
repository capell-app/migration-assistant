<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Services\Import\Resolvers\KeyedMatchResolver;

it('matches a global layout by key even when scoped to specific sites', function (): void {
    $layout = Layout::factory()->create(['key' => 'shared-layout']);

    $resolver = new KeyedMatchResolver(Layout::class, scopeToSite: true);

    expect($resolver->resolve(['key' => 'shared-layout'], 9999999)?->localId)->toBe($layout->getKey());
});

it('does not match a private layout belonging to a different site when scoped to site', function (): void {
    $authorisedSite = Site::factory()->create();
    $victimSite = Site::factory()->create();

    Layout::factory()->create(['key' => 'victim-layout', 'site_id' => $victimSite->getKey()]);

    $resolver = new KeyedMatchResolver(Layout::class, scopeToSite: true);

    expect($resolver->resolve(['key' => 'victim-layout'], $authorisedSite->getKey()))->toBeNull();
});

it('matches a private layout belonging to an authorised site when scoped to site', function (): void {
    $authorisedSite = Site::factory()->create();

    $layout = Layout::factory()->create(['key' => 'own-layout', 'site_id' => $authorisedSite->getKey()]);

    $resolver = new KeyedMatchResolver(Layout::class, scopeToSite: true);

    expect($resolver->resolve(['key' => 'own-layout'], $authorisedSite->getKey())?->localId)->toBe($layout->getKey());
});

it('does not fall back to a different site layout by normalised name when scoped to site', function (): void {
    $authorisedSite = Site::factory()->create();
    $victimSite = Site::factory()->create();

    Layout::factory()->create(['name' => '  Legacy Layout  ', 'site_id' => $victimSite->getKey()]);

    $resolver = new KeyedMatchResolver(Layout::class, scopeToSite: true);

    expect($resolver->resolve(['name' => 'legacy layout'], $authorisedSite->getKey()))->toBeNull();
});

it('remains fully unscoped by default, matching a private layout with no site context', function (): void {
    $victimSite = Site::factory()->create();

    $layout = Layout::factory()->create(['key' => 'unscoped-layout', 'site_id' => $victimSite->getKey()]);

    $resolver = new KeyedMatchResolver(Layout::class);

    expect($resolver->resolve(['key' => 'unscoped-layout'])?->localId)->toBe($layout->getKey());
});
