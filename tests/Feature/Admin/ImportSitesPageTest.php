<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Filament\Pages\ImportSitesPage;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\ChecksumGenerator;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('import-sites-page');

function writeSiteImportWizardPackage(string $absolutePath, string $pageUuid, int $sourceSiteId): void
{
    $manifestJson = json_encode([
        'schema_version' => 1,
        'package_type' => 'site-export',
    ], JSON_THROW_ON_ERROR);

    $siteDescriptorJson = json_encode([
        'type' => 'site',
        'ref' => 'site:' . $sourceSiteId,
        'id' => $sourceSiteId,
        'attributes' => [
            'name' => 'Imported Site',
            'status' => true,
            'default' => false,
        ],
    ], JSON_THROW_ON_ERROR);

    $pageJson = json_encode([
        'type' => 'page',
        'uuid' => $pageUuid,
        'id' => 456,
        'attributes' => [
            'title' => 'Imported Site Page',
            'site_id' => $sourceSiteId,
        ],
        'owned_relations' => [
            'page_urls' => [
                ['site_id' => $sourceSiteId, 'language_id' => 1, 'url' => '/imported-site-page'],
            ],
        ],
        'shared_relations' => [
            'site' => ['ref' => 'site:' . $sourceSiteId],
        ],
    ], JSON_THROW_ON_ERROR);

    $integrityFiles = [
        'manifest.json' => ChecksumGenerator::forString($manifestJson),
        'relations/sites/source-site.json' => ChecksumGenerator::forString($siteDescriptorJson),
        sprintf('pages/%s.json', $pageUuid) => ChecksumGenerator::forString($pageJson),
    ];

    $zipArchive = new ZipArchive;
    $zipArchive->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zipArchive->addFromString('manifest.json', $manifestJson);
    $zipArchive->addFromString('integrity.json', json_encode(['files' => $integrityFiles], JSON_THROW_ON_ERROR));
    $zipArchive->addFromString('relations/sites/source-site.json', $siteDescriptorJson);
    $zipArchive->addFromString(sprintf('pages/%s.json', $pageUuid), $pageJson);
    $zipArchive->close();
}

function stageSiteImportWizardPackage(string $relativePath, string $pageUuid, int $sourceSiteId): void
{
    $absolutePath = Storage::disk('local')->path($relativePath);

    if (! is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0777, true);
    }

    writeSiteImportWizardPackage($absolutePath, $pageUuid, $sourceSiteId);
}

beforeEach(function (): void {
    migrationAssistantActingAsImportPagesUser();
    Storage::fake('local');
    Queue::fake();
});

it('runs the site import wizard through to queued execution', function (): void {
    $pageUuid = (string) Str::uuid();
    $sourceSiteId = 654;
    $workspaceName = 'Site Import Workspace';
    $relativePath = 'migration-assistant/imports/staged/site-import-wizard.zip';

    stageSiteImportWizardPackage($relativePath, $pageUuid, $sourceSiteId);

    $component = Livewire::test(ImportSitesPage::class)
        ->set('data.archive', $relativePath)
        ->set('data.archive_filename', 'site-import-wizard.zip')
        ->set('data.workspace_name', $workspaceName)
        ->call('parseAndAdvance')
        ->assertSet('step', ImportSitesPage::STEP_REVIEW)
        ->assertSet('reviewRows.0.uuid', $pageUuid)
        ->assertSet(sprintf('pageDecisions.%s.action', $pageUuid), PageReviewRow::ACTION_CREATE)
        ->assertSet('resolveRows.0.ref', 'site:' . $sourceSiteId)
        ->call('advanceToResolve')
        ->assertSet('step', ImportSitesPage::STEP_RESOLVE)
        ->call('advanceToValidate')
        ->assertSet('step', ImportSitesPage::STEP_VALIDATE)
        ->set('confirmation', $workspaceName)
        ->call('dispatchImport')
        ->assertSet('step', ImportSitesPage::STEP_EXECUTING)
        ->assertSet('sessionStatus', ImportSessionStatus::Queued->value);

    $sessionId = $component->get('sessionId');
    throw_unless(is_numeric($sessionId), RuntimeException::class, 'Expected site import session id to be numeric.');

    $session = ImportSession::query()->whereKey((int) $sessionId)->firstOrFail();

    expect($session->kind)->toBe(ImportSessionKind::SiteImport)
        ->and($session->status)->toBe(ImportSessionStatus::Queued)
        ->and($session->source_filename)->toBe('site-import-wizard.zip');

    Queue::assertPushed(ExecuteImportPlanJob::class, 1);
});
