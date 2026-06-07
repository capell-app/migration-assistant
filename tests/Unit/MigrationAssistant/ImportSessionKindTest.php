<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Enums\ImportSessionKind;

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
