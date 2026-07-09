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
    expect(ImportSessionKind::PageImport->getLabel())->toBe(__('capell-migration-assistant::imports.enums.import_session_kind.page-import'))
        ->and(ImportSessionKind::SiteImport->getLabel())->toBe(__('capell-migration-assistant::imports.enums.import_session_kind.site-import'))
        ->and(ImportSessionStatus::Draft->getLabel())->toBe(__('capell-migration-assistant::imports.enums.import_session_status.draft'))
        ->and(ImportSessionStatus::Completed->getLabel())->toBe(__('capell-migration-assistant::imports.enums.import_session_status.completed'))
        ->and(ImportSessionStatus::Abandoned->getLabel())->toBe(__('capell-migration-assistant::imports.enums.import_session_status.abandoned'))
        ->and(PackageType::PageExport->getLabel())->toBe(__('capell-migration-assistant::imports.enums.package_type.page-export'))
        ->and(PackageType::SiteExport->getLabel())->toBe(__('capell-migration-assistant::imports.enums.package_type.site-export'));
});
