<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Support\ChecksumGenerator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('import-pages-page');

function importJourneyDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

function importJourneyNodeCount(DOMXPath $xpath, string $expression): int
{
    $nodes = $xpath->query($expression);
    throw_if($nodes === false, RuntimeException::class, 'Expected a valid journey XPath expression.');

    return $nodes->length;
}

function writeImportPackage(string $absolutePath, string $uuid, int $siteId, string $url): void
{
    $manifestJson = json_encode([
        'schema_version' => 1,
        'package_type' => 'page-export',
    ], JSON_THROW_ON_ERROR);

    $pageJson = json_encode([
        'type' => 'page',
        'uuid' => $uuid,
        'id' => 123,
        'attributes' => ['title' => 'Imported Page'],
        'owned_relations' => [
            'page_urls' => [
                ['site_id' => $siteId, 'language_id' => 1, 'url' => $url],
            ],
        ],
        'shared_relations' => [
            'site' => ['ref' => 'site:' . $siteId],
        ],
    ], JSON_THROW_ON_ERROR);

    $integrity = ['files' => [
        'manifest.json' => ChecksumGenerator::forString($manifestJson),
        sprintf('pages/%s.json', $uuid) => ChecksumGenerator::forString($pageJson),
    ]];

    $zip = new ZipArchive;
    $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifestJson);
    $zip->addFromString('integrity.json', json_encode($integrity, JSON_THROW_ON_ERROR));
    $zip->addFromString(sprintf('pages/%s.json', $uuid), $pageJson);
    $zip->close();
}

beforeEach(function (): void {
    migrationAssistantActingAsImportPagesUser();
    $this->fakeMigrationAssistantLocalStorage();
    Queue::fake();
});

it('transitions to review step after parsing a package', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();

    $relativePath = 'migration-assistant/imports/staged/test-package.zip';
    $absolutePath = Storage::disk('local')->path($relativePath);
    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    writeImportPackage($absolutePath, $uuid, (int) $site->getKey(), '/hello-world');

    Livewire::test(ImportPagesPage::class)
        ->set('data.archive', [$relativePath])
        ->set('data.archive_filename', [$relativePath => 'test-package.zip'])
        ->set('data.workspace_name', 'Import test')
        ->call('parseAndAdvance')
        ->assertSet('step', ImportPagesPage::STEP_REVIEW)
        ->assertSet('reviewRows.0.uuid', $uuid)
        ->assertSet(sprintf('pageDecisions.%s.action', $uuid), PageReviewRow::ACTION_CREATE);

    Queue::assertNotPushed(ExecuteImportPlanJob::class);
});

it('renders one page root and six journey steps with one current result', function (string $step, int $position): void {
    $component = Livewire::test(ImportPagesPage::class)->set('step', $step);
    $xpath = importJourneyDom($component->html());

    expect(importJourneyNodeCount($xpath, '/html/body/*[not(self::script)]'))->toBe(1)
        ->and(importJourneyNodeCount($xpath, '//nav[@aria-label="Current step"]/ol/li'))->toBe(6)
        ->and(importJourneyNodeCount($xpath, '//nav//*[@aria-current="step"]'))->toBe(1)
        ->and($xpath->evaluate('normalize-space(//nav//span[@aria-current="step"])'))->toBe((string) $position);
})->with([
    'source' => [ImportPagesPage::STEP_UPLOAD, 1],
    'review' => [ImportPagesPage::STEP_REVIEW, 2],
    'resolve' => [ImportPagesPage::STEP_RESOLVE, 3],
    'validate' => [ImportPagesPage::STEP_VALIDATE, 4],
    'import' => [ImportPagesPage::STEP_EXECUTING, 5],
    'completed result' => [ImportPagesPage::STEP_COMPLETED, 6],
    'failed result' => [ImportPagesPage::STEP_FAILED, 6],
]);

it('discloses actual confident matches while leading with unresolved relations', function (string $pageClass): void {
    $component = Livewire::test($pageClass)
        ->set('resolveRows', [
            ['group' => 'layouts', 'ref' => 'layout:matched', 'top_match' => ['local_id' => 42, 'strategy' => 'fingerprint', 'confidence' => 1.0, 'reason' => 'Identical layout structure'], 'alternatives' => [], 'warnings' => []],
            ['group' => 'layouts', 'ref' => 'layout:missing', 'top_match' => null, 'alternatives' => [], 'warnings' => []],
        ])
        ->set('relationDecisions', [
            'layout:matched' => ['action' => 'use_existing', 'target_id' => 42],
            'layout:missing' => ['action' => 'create_new'],
        ])
        ->set('step', ImportPagesPage::STEP_REVIEW)
        ->assertSee(__('migration-assistant::imports.journey.review_blocking_heading'));

    $xpath = importJourneyDom($component->html());
    expect(importJourneyNodeCount($xpath, '//details[not(@open)]//*[contains(text(), "layout:matched")]'))->toBeGreaterThan(0)
        ->and(importJourneyNodeCount($xpath, '//details[not(@open)]//*[contains(text(), "Identical layout structure")]'))->toBeGreaterThan(0)
        ->and(importJourneyNodeCount($xpath, '//details[not(@open)]//*[contains(text(), "Target 42") and contains(text(), "fingerprint") and contains(text(), "100% confidence")]'))->toBe(1)
        ->and(importJourneyNodeCount($xpath, '//section[@aria-labelledby="migration-review-blockers"]/following-sibling::details'))->toBe(1);
})->with([ImportPagesPage::class, ImportSitesPage::class]);

it('rejects hostile Livewire archive paths without reading local storage', function (): void {
    Storage::disk('local')->put('private/never-import.zip', 'not a migration package');

    Livewire::test(ImportPagesPage::class)
        ->set('data.archive', '../../private/never-import.zip')
        ->set('data.archive_filename', 'never-import.zip')
        ->set('data.workspace_name', 'Hostile import')
        ->call('parseAndAdvance')
        ->assertSet('step', ImportPagesPage::STEP_UPLOAD);

    Storage::disk('local')->assertExists('private/never-import.zip');
});

it('rejects replayed Livewire migration uploads after the token is consumed', function (): void {
    $site = Site::factory()->create();
    $uuid = (string) Str::uuid();
    $relativePath = 'migration-assistant/imports/staged/replayed-package.zip';
    $absolutePath = Storage::disk('local')->path($relativePath);
    mkdir(dirname($absolutePath), 0777, true);
    writeImportPackage($absolutePath, $uuid, (int) $site->getKey(), '/replayed-package');

    Livewire::test(ImportPagesPage::class)
        ->set('data.archive', $relativePath)
        ->set('data.archive_filename', 'replayed-package.zip')
        ->set('data.workspace_name', 'Replay test')
        ->call('parseAndAdvance')
        ->assertSet('step', ImportPagesPage::STEP_REVIEW)
        ->call('backToUpload')
        ->set('data.archive', $relativePath)
        ->call('parseAndAdvance')
        ->assertSet('step', ImportPagesPage::STEP_UPLOAD);
});
