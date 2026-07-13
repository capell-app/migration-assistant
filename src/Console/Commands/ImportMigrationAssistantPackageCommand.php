<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Actions\Imports\AdvancePageImportToValidationAction;
use Capell\MigrationAssistant\Actions\Imports\BindMigrationArchiveUploadAction;
use Capell\MigrationAssistant\Actions\Imports\DispatchPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\StartPageImportAction;
use Capell\MigrationAssistant\Actions\Imports\StartSiteImportAction;
use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Capell\MigrationAssistant\Data\RelationResolveRow;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Jobs\ExecuteImportPlanJob;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Services\Import\MediaIngestService;
use Capell\MigrationAssistant\Services\Import\PackageReader;
use Capell\MigrationAssistant\Services\Import\PageImportService;
use Capell\MigrationAssistant\Services\Import\SiteImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Override;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ImportMigrationAssistantPackageCommand extends Command
{
    protected $signature = 'migration-assistant:import
        {archive : Absolute or relative path to a migration package ZIP}
        {--kind=page-import : Import kind: page-import or site-import}
        {--workspace-name= : Target workspace/site label for the import session}
        {--execute : Queue the import after validation passes}
        {--sync : Run the import job synchronously after validation passes}
        {--json : Output import data as JSON}';

    protected $description = 'Create, validate, and optionally execute a Migration Assistant import session.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.import.description');
    }

    public function handle(): int
    {
        $archivePath = $this->archivePath();

        if ($archivePath === null) {
            $this->components->error((string) __('migration-assistant::commands.import.archive_not_found'));

            return SymfonyCommand::FAILURE;
        }

        $kind = $this->importKind();

        if (! $kind instanceof ImportSessionKind) {
            $this->components->error((string) __('migration-assistant::commands.import.invalid_kind'));

            return SymfonyCommand::FAILURE;
        }

        $storedArchivePath = $this->storeArchive($archivePath);
        $state = (new BindMigrationArchiveUploadAction)->handle([
            'archive' => $storedArchivePath,
            'archive_filename' => basename($archivePath),
            'workspace_name' => $this->stringOption('workspace-name') ?? __('capell-admin::exchanger.import_workspace_default_name'),
        ]);

        $startedState = $kind === ImportSessionKind::SiteImport
            ? StartSiteImportAction::run($state)
            : StartPageImportAction::run($state);

        $validatedState = AdvancePageImportToValidationAction::run(
            new PageImportDecisionData(
                sessionId: $startedState->sessionId,
                reviewRows: $startedState->reviewRows,
                pageDecisions: $this->defaultPageDecisions($startedState),
                resolveRows: $startedState->resolveRows,
                relationDecisions: $this->defaultRelationDecisions($startedState),
                canUpdateSharedRelations: false,
            ),
            forceValidation: true,
        );

        $session = $this->session($validatedState->sessionId);
        if (! $session instanceof ImportSession) {
            $this->components->error((string) __('migration-assistant::commands.import.session_not_found'));

            return SymfonyCommand::FAILURE;
        }

        if ($this->hasBlockingValidationErrors($session)) {
            $this->writeSessionResult($session);

            return SymfonyCommand::FAILURE;
        }

        if ((bool) $this->option('sync')) {
            $session->forceFill(['status' => ImportSessionStatus::Queued])->save();
            (new ExecuteImportPlanJob($this->sessionId($session)))->handle(
                resolve(PackageReader::class),
                resolve(PageImportService::class),
                resolve(MediaIngestService::class),
                resolve(SiteImportService::class),
            );
            $session->refresh();
        } elseif ((bool) $this->option('execute')) {
            $validationResults = $this->validationResults($session);
            $confirmationExpected = $this->confirmationExpected($validationResults);

            DispatchPageImportAction::run(
                $this->sessionId($session),
                $validationResults,
                $confirmationExpected,
                $confirmationExpected,
            );
            $session->refresh();
        }

        $this->writeSessionResult($session);

        return SymfonyCommand::SUCCESS;
    }

    private function hasBlockingValidationErrors(ImportSession $session): bool
    {
        $validationResults = is_array($session->validation_results) ? $session->validation_results : [];
        $blockingErrors = $validationResults['blocking_errors'] ?? [];

        return is_array($blockingErrors) && $blockingErrors !== [];
    }

    private function writeSessionResult(ImportSession $session): void
    {
        $result = [
            'id' => $session->getKey(),
            'uuid' => $session->uuid,
            'kind' => $session->kind->value,
            'status' => $session->status->value,
            'source' => $session->source_filename,
            'validation' => $session->validation_results,
        ];

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->components->info((string) __('migration-assistant::commands.import.completed', [
            'session' => $session->uuid,
            'status' => $session->status->value,
        ]));
    }

    /**
     * @return array<string, array{action: string, notes?: string}>
     */
    private function defaultPageDecisions(PageImportWizardStateData $state): array
    {
        $decisions = $state->pageDecisions;

        foreach ($state->reviewRows as $row) {
            if (($row['collision_state'] ?? null) !== PageReviewRow::COLLISION_URL_WORKSPACE) {
                continue;
            }

            $uuid = $row['uuid'] ?? null;

            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            $decisions[$uuid] = ['action' => PageReviewRow::ACTION_SKIP];
        }

        return $decisions;
    }

    /**
     * @return array<string, array{action: string, target_id?: int|string|null, notes?: string}>
     */
    private function defaultRelationDecisions(PageImportWizardStateData $state): array
    {
        $decisions = $state->relationDecisions;

        foreach ($state->resolveRows as $row) {
            $ref = $row['ref'] ?? null;

            if (! is_string($ref) || $ref === '' || isset($decisions[$ref])) {
                continue;
            }

            $decisions[$ref] = ['action' => RelationResolveRow::ACTION_CLONE_IMPORTED];
        }

        return $decisions;
    }

    private function archivePath(): ?string
    {
        $argument = $this->argument('archive');
        if (! is_string($argument) || $argument === '') {
            return null;
        }

        $path = realpath($argument);

        return is_string($path) && is_file($path) ? $path : null;
    }

    private function importKind(): ?ImportSessionKind
    {
        $kind = $this->stringOption('kind') ?? ImportSessionKind::PageImport->value;

        return ImportSessionKind::tryFrom($kind);
    }

    private function storeArchive(string $archivePath): string
    {
        $storedPath = BindMigrationArchiveUploadAction::STAGING_DIRECTORY . '/' . Str::uuid() . '-' . basename($archivePath);
        $disk = config('migration-assistant.disk', 'local');
        Storage::disk(is_string($disk) ? $disk : 'local')->put($storedPath, file_get_contents($archivePath) ?: '');

        return $storedPath;
    }

    private function session(?int $sessionId): ?ImportSession
    {
        if ($sessionId === null) {
            return null;
        }

        return ImportSession::query()->find($sessionId);
    }

    private function sessionId(ImportSession $session): int
    {
        $key = $session->getKey();

        if (is_int($key)) {
            return $key;
        }

        return is_string($key) && ctype_digit($key) ? (int) $key : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function validationResults(ImportSession $session): array
    {
        $validationResults = $session->validation_results;

        if (! is_array($validationResults)) {
            return [];
        }

        $normalizedResults = [];

        foreach ($validationResults as $key => $value) {
            if (is_string($key)) {
                $normalizedResults[$key] = $value;
            }
        }

        return $normalizedResults;
    }

    /**
     * @param  array<string, mixed>  $validationResults
     */
    private function confirmationExpected(array $validationResults): string
    {
        $confirmationExpected = $validationResults['confirmation_expected'] ?? '';

        return is_string($confirmationExpected) ? $confirmationExpected : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
