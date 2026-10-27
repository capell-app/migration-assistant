<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Actions\BuildImportValidationSummaryAction;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function validationEnvelope(string $uuid, int $siteId, string $url, array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'type' => 'page',
        'uuid' => $uuid,
        'attributes' => ['title' => 'Imported'],
        'owned_relations' => [
            'page_urls' => [
                ['site_id' => $siteId, 'language_id' => 1, 'url' => $url],
            ],
        ],
        'shared_relations' => [
            'site' => ['ref' => 'site:' . $siteId],
        ],
    ], $overrides));
}

function validationPackage(string $uuid, int $siteId, string $url): PackageReadResult
{
    return new PackageReadResult(
        archivePath: '',
        manifest: [],
        integrity: [],
        payload: [sprintf('pages/%s.json', $uuid) => validationEnvelope($uuid, $siteId, $url)],
    );
}

function siteResolvedMap(Site $site): ResolutionMap
{
    return new ResolutionMap(
        resolved: [
            'site:' . $site->getKey() => new MatchResolution(
                localId: (int) $site->getKey(),
                strategy: 'slug',
            ),
        ],
        unresolved: [],
    );
}

it('produces clean counts for a basic package', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();

    $summary = (new BuildImportValidationSummaryAction)->run(
        package: validationPackage($uuid, (int) $site->getKey(), '/fresh'),
        map: siteResolvedMap($site),
        pageDecisions: [$uuid => ['action' => PageReviewRow::ACTION_CREATE]],
        relationDecisions: [
            'site:' . $site->getKey() => [
                'action' => RelationResolveRow::ACTION_USE_EXISTING,
                'target_id' => $site->getKey(),
            ],
        ],
    );

    expect($summary->pages)->toBe(['create' => 1, 'update' => 0, 'skip' => 0])
        ->and($summary->relations['match'])->toBe(1)
        ->and($summary->blockingErrors)->toBe([])
        ->and($summary->isClean())->toBeTrue();
});

it('warns when the resolver surfaces low-confidence alternatives', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();

    $siteRef = 'site:' . $site->getKey();
    $map = new ResolutionMap(
        resolved: [
            $siteRef => new MatchResolution(
                localId: (int) $site->getKey(),
                strategy: 'slug',
                confidence: 0.9,
                alternatives: [new MatchResolution(localId: 9999, strategy: 'slug', confidence: 0.3)],
            ),
        ],
        unresolved: [],
    );

    $summary = (new BuildImportValidationSummaryAction)->run(
        package: validationPackage($uuid, (int) $site->getKey(), '/low-conf'),
        map: $map,
        pageDecisions: [$uuid => ['action' => PageReviewRow::ACTION_CREATE]],
        relationDecisions: [$siteRef => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => $site->getKey()]],
    );

    expect($summary->warnings)->not->toBeEmpty();
});

it('buckets media into import vs reuse based on resolution', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();

    $siteRef = 'site:' . $site->getKey();
    $resolvedMediaRef = 'media:100';
    $unresolvedMediaRef = 'media:200';

    $package = new PackageReadResult(
        archivePath: '',
        manifest: [],
        integrity: [],
        payload: [
            sprintf('pages/%s.json', $uuid) => validationEnvelope($uuid, (int) $site->getKey(), '/media'),
            'relations/media/100.json' => (string) json_encode(['ref' => $resolvedMediaRef]),
            'relations/media/200.json' => (string) json_encode(['ref' => $unresolvedMediaRef]),
        ],
    );

    $map = new ResolutionMap(
        resolved: [
            $siteRef => new MatchResolution(localId: (int) $site->getKey(), strategy: 'slug'),
            $resolvedMediaRef => new MatchResolution(localId: 500, strategy: 'checksum'),
        ],
        unresolved: [$unresolvedMediaRef],
    );

    $summary = (new BuildImportValidationSummaryAction)->run(
        package: $package,
        map: $map,
        pageDecisions: [$uuid => ['action' => PageReviewRow::ACTION_CREATE]],
        relationDecisions: [
            $siteRef => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => $site->getKey()],
            $resolvedMediaRef => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => 500],
            $unresolvedMediaRef => ['action' => RelationResolveRow::ACTION_CREATE_NEW],
        ],
    );

    expect($summary->media)->toBe(['import' => 1, 'reuse' => 1]);
});

it('treats an unresolved ref with use_existing as a blocking error', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();

    $map = new ResolutionMap(
        resolved: [
            'site:' . $site->getKey() => new MatchResolution(localId: (int) $site->getKey(), strategy: 'slug'),
        ],
        unresolved: ['layout:999'],
    );

    $summary = (new BuildImportValidationSummaryAction)->run(
        package: validationPackage($uuid, (int) $site->getKey(), '/unresolved'),
        map: $map,
        pageDecisions: [$uuid => ['action' => PageReviewRow::ACTION_CREATE]],
        relationDecisions: [
            'site:' . $site->getKey() => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => $site->getKey()],
            'layout:999' => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => null],
        ],
    );

    expect($summary->blockingErrors)->not->toBeEmpty();
});

it('keeps concrete live URL and low confidence decisions visible without making warnings blockers', function (): void {
    $language = Language::factory()->create();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $page = Page::factory()->site($site)->withTranslations($language, slug: '/collision-review')->create();
    $uuid = (string) Str::uuid();
    $ref = 'site:' . $site->getKey();
    $map = new ResolutionMap(resolved: [
        $ref => new MatchResolution(localId: (int) $site->getKey(), strategy: 'slug', alternatives: [
            new MatchResolution(localId: 9876, strategy: 'slug', confidence: 0.3),
        ]),
    ], unresolved: []);
    $summary = (new BuildImportValidationSummaryAction)->run(
        validationPackage($uuid, (int) $site->getKey(), '/collision-review'),
        $map,
        [$uuid => ['action' => PageReviewRow::ACTION_CREATE]],
        [$ref => ['action' => RelationResolveRow::ACTION_USE_EXISTING, 'target_id' => $site->getKey()]],
    );

    expect($summary->warnings)->toHaveCount(2)
        ->and(implode(' ', $summary->warnings))->toContain('Imported', $ref, '9876', '30%')
        ->and($summary->blockingErrors)->toBe([])
        ->and($summary->isClean())->toBeTrue()
        ->and($summary->pages['create'])->toBe(1)
        ->and($page->fresh())->not->toBeNull();

    $this->actingAsAdmin();
    $view = Livewire::test(ImportPagesPage::class)
        ->set('validationSummary', $summary->toArray())
        ->set('step', ImportPagesPage::STEP_VALIDATE);
    foreach ($summary->warnings as $warning) {
        $view->assertSee($warning);
    }

    $view->assertSee(__('migration-assistant::imports.journey.warning_decisions'));
});
