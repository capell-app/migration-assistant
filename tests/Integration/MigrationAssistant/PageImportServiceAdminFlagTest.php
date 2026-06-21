<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Services\Import\PackageReadResult;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\ResolutionMap;
use Capell\MigrationAssistant\Services\Import\Resolvers\MatchResolution;
use Illuminate\Support\Str;

/**
 * Builds a page export descriptor whose attributes carry an attacker-controlled
 * `admin` payload, used to assert the importer never honours it.
 *
 * @param  array<string, mixed>  $adminPayload
 */
function makePageDescriptorWithAdminPayload(
    Layout $layout,
    Blueprint $type,
    Site $site,
    array $adminPayload,
    int $sourceId = 7700,
): string {
    $layoutReference = importResolutionReference('layout', $layout->getKey());
    $typeReference = importResolutionReference('type', $type->getKey());
    $siteReference = importResolutionReference('site', $site->getKey());

    $descriptor = [
        'type' => 'page',
        'uuid' => (string) Str::uuid(),
        'id' => $sourceId,
        'attributes' => [
            'id' => $sourceId,
            'uuid' => (string) Str::uuid(),
            'name' => 'Malicious Page ' . $sourceId,
            'layout_id' => $layout->getKey(),
            'blueprint_id' => $type->getKey(),
            'site_id' => $site->getKey(),
            'parent_id' => null,
            'admin' => $adminPayload,
        ],
        'owned_relations' => ['page_urls' => []],
        'shared_relations' => [
            'layout' => ['ref' => $layoutReference],
            'type' => ['ref' => $typeReference],
            'site' => ['ref' => $siteReference],
        ],
        'media_bindings' => [],
    ];

    return (string) json_encode($descriptor);
}

function importResolutionReference(string $prefix, mixed $key): string
{
    return sprintf('%s:%s', $prefix, is_scalar($key) ? (string) $key : '');
}

it('does not honour the admin flag from the untrusted import payload', function (): void {
    $layout = Layout::factory()->create();
    $type = Blueprint::factory()->create();
    $site = Site::factory()->create();

    $maliciousAdminPayload = [
        'system_page_layout' => true,
        'resource' => 'super-admin',
        'image_source_policy' => ['hero' => 'elevated'],
    ];

    $package = new PackageReadResult(
        archivePath: '',
        manifest: [],
        integrity: [],
        payload: [
            'pages/malicious.json' => makePageDescriptorWithAdminPayload(
                $layout,
                $type,
                $site,
                adminPayload: $maliciousAdminPayload,
            ),
        ],
    );

    $resolutionMap = new ResolutionMap(
        resolved: [
            'layout:' . $layout->getKey() => new MatchResolution(localId: (int) $layout->getKey(), strategy: 'key'),
            'type:' . $type->getKey() => new MatchResolution(localId: (int) $type->getKey(), strategy: 'key'),
            'site:' . $site->getKey() => new MatchResolution(localId: (int) $site->getKey(), strategy: 'slug'),
        ],
        unresolved: [],
    );

    $report = (new PageImportService)->import($package, $resolutionMap);

    expect($report->errors)->toBe([])
        ->and($report->pagesCreated)->toBe(1);

    $page = Page::query()->withoutGlobalScopes()->whereKey($report->createdPageIds[0])->firstOrFail();

    // The payload-supplied admin metadata must not survive the import: the
    // importer sets `admin` server-side to a non-elevated default (null).
    expect($page->getAttribute('admin'))->toBeNull();
});
