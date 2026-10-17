<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('import-pages-page-journey');

beforeEach(function (): void {
    migrationAssistantActingAsImportPagesUser();
    $this->fakeMigrationAssistantLocalStorage();
});

it('renders the package-owned view with the complete progress rail', function (): void {
    $html = Livewire::test(ImportPagesPage::class)->html();

    throw_if($html === '', RuntimeException::class, 'Expected rendered journey HTML.');
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $steps = $xpath->query('//nav/ol/li/span[2]');
    throw_unless($steps instanceof DOMNodeList, RuntimeException::class, 'Expected journey steps.');
    $labels = [];
    foreach ($steps as $label) {
        throw_unless($label instanceof DOMNode, RuntimeException::class, 'Expected a rendered step label.');
        $labels[] = trim($label->textContent);
    }
    expect($labels)->toBe([
        __('migration-assistant::imports.journey.source'),
        __('migration-assistant::imports.journey.review'),
        __('migration-assistant::imports.journey.resolve'),
        __('migration-assistant::imports.journey.validate'),
        __('migration-assistant::imports.journey.import'),
        __('migration-assistant::imports.journey.result'),
    ])
        ->and($xpath->evaluate('normalize-space(//nav//span[@aria-current="step"])'))->toBe('1');

    $reviewHtml = Livewire::test(ImportPagesPage::class)->set('step', ImportPagesPage::STEP_REVIEW)->html();
    throw_if($reviewHtml === '', RuntimeException::class, 'Expected rendered review HTML.');
    $document->loadHTML($reviewHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
    expect((new DOMXPath($document))->evaluate('normalize-space(//nav//span[@aria-current="step"])'))->toBe('2');
});

it('discloses review blockers, technical matches, the write boundary, and report inspection', function (): void {
    $component = Livewire::test(ImportPagesPage::class)
        ->set('step', ImportPagesPage::STEP_REVIEW)
        ->set('reviewRows', [[
            'uuid' => 'page-1',
            'collision_state' => 'url_conflict_live',
        ]])
        ->set('resolveRows', [['ref' => 'layout:1']])
        ->set('relationDecisions', [])
        ->assertSee(__('migration-assistant::imports.journey.review_blocking_heading'))
        ->assertSee(__('migration-assistant::imports.journey.technical_details'));

    $component
        ->set('step', ImportPagesPage::STEP_VALIDATE)
        ->assertSee(__('migration-assistant::imports.journey.write_boundary'))
        ->set('step', ImportPagesPage::STEP_COMPLETED)
        ->assertSee(__('migration-assistant::imports.journey.rollback_report_reference'));
});
