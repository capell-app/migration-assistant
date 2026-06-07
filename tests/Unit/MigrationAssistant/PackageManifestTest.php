<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Enums\PackageType;
use Carbon\CarbonImmutable;

it('serialises into a stable manifest array', function (): void {
    $manifest = new PackageManifest(
        packageType: PackageType::PageExport,
        capellVersion: '12.0.0',
        exportedAt: CarbonImmutable::parse('2026-04-17T00:00:00Z'),
        sourceEnvironment: 'testing',
        sourceLiveVersionId: 42,
        pageCount: 2,
        siteCount: 0,
        relationCounts: ['layouts' => 1],
        note: 'release candidate',
        sourceWorkspaceId: 3,
    );

    $array = $manifest->withChecksums(['payload' => 'sha256-abc'])->toArray();

    expect($array)
        ->toHaveKey('schema_version', PackageManifest::SCHEMA_VERSION)
        ->toHaveKey('package_type', 'page-export')
        ->toHaveKey('source_workspace_id', 3)
        ->toHaveKey('source_live_version_id', 42)
        ->toHaveKey('page_count', 2)
        ->toHaveKey('relation_counts', ['layouts' => 1])
        ->toHaveKey('note', 'release candidate')
        ->toHaveKey('checksums', ['payload' => 'sha256-abc']);
});

it('declares all committed marketplace screenshots', function (): void {
    $packagePath = dirname(__DIR__, 3);
    $manifest = capell_json_file_array($packagePath . '/capell.json');
    $marketplaceScreenshots = data_get($manifest, 'marketplace.screenshots', []);

    throw_unless(is_array($marketplaceScreenshots), RuntimeException::class, 'Migration Assistant marketplace screenshots must be an array.');

    $declaredPaths = array_map(
        static function (mixed $screenshot): string {
            throw_unless(is_array($screenshot), RuntimeException::class, 'Migration Assistant marketplace screenshot entries must be arrays.');

            $path = $screenshot['path'] ?? null;
            $alt = $screenshot['alt'] ?? null;
            $caption = $screenshot['caption'] ?? null;

            throw_unless(is_string($path), RuntimeException::class, 'Migration Assistant marketplace screenshot paths must be strings.');
            throw_unless(is_string($alt), RuntimeException::class, 'Migration Assistant marketplace screenshot alt text must be strings.');
            throw_unless(is_string($caption), RuntimeException::class, 'Migration Assistant marketplace screenshot captions must be strings.');

            return $path;
        },
        $marketplaceScreenshots,
    );

    $requiredPaths = [
        'docs/assets/marketplace/extension-card.jpg',
    ];

    expect($declaredPaths)->toContain(...$requiredPaths);

    foreach ($declaredPaths as $declaredPath) {
        expect(file_exists($packagePath . '/' . $declaredPath))->toBeTrue();
    }
});

it('declares the measured admin wizard query budget', function (): void {
    $packagePath = dirname(__DIR__, 3);
    $manifest = capell_json_file_array($packagePath . '/capell.json');

    expect(data_get($manifest, 'performance.adminQueryBudget'))->toBe(120);
});
