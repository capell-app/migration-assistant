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
    $view = new ReflectionClass(ImportPagesPage::class)
        ->getProperty('view')
        ->getDefaultValue();

    expect($view)->toBe('migration-assistant::pages.import-pages');

    $html = Livewire::test(ImportPagesPage::class)->html();

    expect($html)->toContain(
        __('migration-assistant::imports.journey.source'),
        __('migration-assistant::imports.journey.review'),
        __('migration-assistant::imports.journey.resolve'),
        __('migration-assistant::imports.journey.validate'),
        __('migration-assistant::imports.journey.import'),
        __('migration-assistant::imports.journey.result'),
    );
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
