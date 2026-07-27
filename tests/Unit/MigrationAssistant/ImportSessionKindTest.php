<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Enums\PackageType;

it('only exposes package-owned archive import session kinds', function (): void {
    expect(array_map(
        static fn (ImportSessionKind $kind): string => $kind->value,
        ImportSessionKind::cases(),
    ))->toBe([
        'page-import',
        'site-import',
    ]);
});

it('does not reserve session kinds for downstream source readers', function (): void {
    expect(array_map(
        static fn (ImportSessionKind $kind): string => $kind->name,
        ImportSessionKind::cases(),
    ))->not->toContain('WordPressImport', 'SpreadsheetImport');
});

it('labels persisted migration assistant enum values for Filament surfaces', function (): void {
    expect(ImportSessionKind::PageImport->getLabel())->toBe('Page import')
        ->and(ImportSessionKind::SiteImport->getLabel())->toBe('Site import')
        ->and(ImportSessionStatus::Draft->getLabel())->toBe('Draft')
        ->and(ImportSessionStatus::Completed->getLabel())->toBe('Completed')
        ->and(ImportSessionStatus::Abandoned->getLabel())->toBe('Abandoned')
        ->and(PackageType::PageExport->getLabel())->toBe('Page export')
        ->and(PackageType::SiteExport->getLabel())->toBe('Site export');
});
