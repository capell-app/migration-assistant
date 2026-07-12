<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Console\Commands;

use Capell\MigrationAssistant\Data\ExportOptions;
use Capell\MigrationAssistant\Services\Export\PageExportService;
use Illuminate\Console\Command;
use Override;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ExportMigrationAssistantPackageCommand extends Command
{
    protected $signature = 'migration-assistant:export
        {--page=* : Page id to include in a page-export package}
        {--site=* : Site id to include in a site-export package}
        {--note= : Optional note to store in the package manifest}
        {--without-translations : Exclude translated page content}
        {--without-media : Exclude media binaries}
        {--without-shared-relations : Exclude shared relation payloads}
        {--include-all-contexts : Include all configured source contexts when a context resolver supports it}
        {--source-workspace= : Source workspace/context id for context-aware exports}
        {--json : Output export data as JSON}';

    protected $description = 'Export a Capell migration package from page or site ids.';

    public function __construct(
        private readonly PageExportService $pageExportService,
    ) {
        parent::__construct();
    }

    #[Override]
    public function getDescription(): string
    {
        return (string) __('migration-assistant::commands.export.description');
    }

    public function handle(): int
    {
        $pageIds = $this->integerListOption('page');
        $siteIds = $this->integerListOption('site');

        if (($pageIds === [] && $siteIds === []) || ($pageIds !== [] && $siteIds !== [])) {
            $this->components->error((string) __('migration-assistant::commands.export.require_scope'));

            return SymfonyCommand::FAILURE;
        }

        $path = $pageIds !== []
            ? $this->pageExportService->exportPages($pageIds, $this->exportOptions())
            : $this->pageExportService->exportSites($siteIds, $this->exportOptions());

        $result = [
            'type' => $pageIds !== [] ? 'page-export' : 'site-export',
            'path' => $path,
            'count' => count($pageIds !== [] ? $pageIds : $siteIds),
        ];

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return SymfonyCommand::SUCCESS;
        }

        $this->components->info((string) __('migration-assistant::commands.export.completed', [
            'path' => $path,
        ]));

        return SymfonyCommand::SUCCESS;
    }

    private function exportOptions(): ExportOptions
    {
        $sourceWorkspace = $this->option('source-workspace');

        return new ExportOptions(
            includeTranslations: ! (bool) $this->option('without-translations'),
            includeMedia: ! (bool) $this->option('without-media'),
            includeSharedRelations: ! (bool) $this->option('without-shared-relations'),
            includeAllContexts: (bool) $this->option('include-all-contexts'),
            note: $this->stringOption('note'),
            includeDrafts: false,
            sourceWorkspace: is_numeric($sourceWorkspace) ? (int) $sourceWorkspace : null,
        );
    }

    /**
     * @return list<int>
     */
    private function integerListOption(string $name): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
