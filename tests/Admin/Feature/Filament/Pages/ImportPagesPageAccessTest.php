<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Actions\Imports\BindMigrationArchiveUploadAction;
use Capell\MigrationAssistant\Actions\Imports\StartSiteImportAction;
use Capell\MigrationAssistant\Enums\MigrationAssistantPermission;
use Capell\MigrationAssistant\Filament\Pages\ImportPagesPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('import-pages-page');

beforeEach(function (): void {
    Permission::findOrCreate('View:ImportPagesPage', 'web');
    Permission::findOrCreate(MigrationAssistantPermission::PageImport->value, 'web');
});

it('refuses the import wizard to an actor with the page permission but no import permission', function (): void {
    $this->actingAsUser();
    $this->authenticatedUser()->givePermissionTo('View:ImportPagesPage');

    expect(ImportPagesPage::canAccess())->toBeFalse();

    Livewire\Livewire::test(ImportPagesPage::class)->assertForbidden();
});

it('opens the import wizard to an actor who may import pages', function (): void {
    migrationAssistantActingAsImportPagesUser();

    expect(ImportPagesPage::canAccess())->toBeTrue();
});

it('caps the size of an uploaded package archive', function (): void {
    config(['migration-assistant.limits.max_package_upload_kilobytes' => 2048]);
    migrationAssistantActingAsImportPagesUser();

    $page = Livewire\Livewire::test(ImportPagesPage::class)->instance();
    throw_unless($page instanceof ImportPagesPage, RuntimeException::class, 'Expected the import pages page.');
    $upload = null;

    foreach ($page->getSchema('form')?->getFlatComponents() ?? [] as $component) {
        if ($component instanceof FileUpload) {
            $upload = $component;
        }
    }

    expect($upload)->not->toBeNull()
        ->and($upload?->getMaxSize())->toBe(2048);
});

it('refuses a staged package larger than the size limit and removes it', function (): void {
    config(['migration-assistant.limits.max_package_upload_kilobytes' => 1]);
    migrationAssistantActingAsImportPagesUser();
    $this->fakeMigrationAssistantLocalStorage();
    $stagedPath = BindMigrationArchiveUploadAction::STAGING_DIRECTORY . '/oversized.zip';
    Storage::disk('local')->put($stagedPath, str_repeat('x', 2048));

    expect(fn (): mixed => BindMigrationArchiveUploadAction::run(['archive' => $stagedPath, 'workspace_name' => 'Oversized']))
        ->toThrow(RuntimeException::class, BindMigrationArchiveUploadAction::ERROR_UPLOAD_TOO_LARGE);

    Storage::disk('local')->assertMissing($stagedPath);
});

it('starts an import with nobody signed in so console imports keep working', function (): void {
    expect(auth()->guest())->toBeTrue()
        ->and(fn (): mixed => StartSiteImportAction::run(['archive' => (string) Str::uuid(), 'workspace_name' => 'Console']))
        ->toThrow(RuntimeException::class, BindMigrationArchiveUploadAction::ERROR_INVALID_UPLOAD);
});
