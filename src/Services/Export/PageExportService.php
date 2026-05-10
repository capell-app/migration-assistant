<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Export;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Contracts\MigrationAssistantContextResolver;
use Capell\MigrationAssistant\Contracts\NullMigrationAssistantContextResolver;
use Capell\MigrationAssistant\Data\ExportOptions;
use Capell\MigrationAssistant\Data\PackageManifest;
use Capell\MigrationAssistant\Enums\PackageType;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Builds a page content-package archive for the given page IDs and returns
 * the absolute path to the resulting ZIP.
 */
final readonly class PageExportService
{
    public function __construct(
        private MigrationAssistantContextResolver $contextResolver = new NullMigrationAssistantContextResolver,
        private DependencyGraphBuilder $graphBuilder = new DependencyGraphBuilder,
        private PayloadSerializer $serializer = new PayloadSerializer,
        private PackageWriter $writer = new PackageWriter,
    ) {}

    /**
     * @param  array<int, int|string>  $pageIds
     */
    public function exportPages(array $pageIds, ExportOptions $options): string
    {
        return $this->runInContext(function () use ($pageIds, $options): string {
            $resolvedPageIds = $this->contextResolver->resolvePageIds($pageIds, $options->sourceWorkspace);

            /** @var Collection<int, Page> $pages */
            $pages = Page::query()->with(['site', 'pageUrls'])->whereIn('id', $resolvedPageIds)->get();

            /** @var Collection<int, Site> $sites */
            $sites = new Collection;

            return $this->write($pages, $sites, $options, PackageType::PageExport, prefix: 'pages');
        }, $options);
    }

    /**
     * @param  array<int, int|string>  $siteIds
     */
    public function exportSites(array $siteIds, ExportOptions $options): string
    {
        return $this->runInContext(function () use ($siteIds, $options): string {
            /** @var Collection<int, Site> $sites */
            $sites = Site::query()->whereIn('id', $siteIds)->get();
            /** @var Collection<int, Page> $pages */
            $pages = Page::query()->with(['site', 'pageUrls'])->whereIn('site_id', $siteIds)->get();

            return $this->write($pages, $sites, $options, PackageType::SiteExport, prefix: 'sites');
        }, $options);
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  Collection<int, Site>  $sites
     */
    private function write(
        Collection $pages,
        Collection $sites,
        ExportOptions $options,
        PackageType $packageType,
        string $prefix,
    ): string {
        $graph = $this->graphBuilder->build($pages, $sites, $options);

        $manifest = new PackageManifest(
            packageType: $packageType,
            capellVersion: app()->version(),
            exportedAt: CarbonImmutable::now('UTC'),
            sourceEnvironment: app()->environment(),
            sourceLiveVersionId: null,
            sourceWorkspaceId: $options->sourceWorkspace,
            pageCount: $graph->pageCount(),
            siteCount: $graph->siteCount(),
            relationCounts: $graph->sharedRelationCounts(),
            note: $options->note,
        );

        $payload = $this->serializer->serialize($graph);

        $media = [];
        foreach ($graph->media as $ref => $descriptor) {
            $media[$ref] = [
                'path' => $descriptor['path'],
                'checksum' => $descriptor['checksum'],
            ];
        }

        $destination = $this->destinationPath($prefix);

        $this->writer->write($destination, $manifest, $graph, $payload, $media);

        return $destination;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function runInContext(Closure $callback, ExportOptions $options): mixed
    {
        return $this->contextResolver->wrap($callback, $options->sourceWorkspace);
    }

    private function destinationPath(string $prefix): string
    {
        $relativePath = config('migration-assistant.paths.exports', 'migration-assistant/exports');

        if (! is_string($relativePath) || $relativePath === '') {
            $relativePath = 'migration-assistant/exports';
        }

        $base = storage_path('app/' . trim($relativePath, '/'));

        $filename = sprintf(
            'capell-cms-%s-%s-%s.zip',
            $prefix,
            CarbonImmutable::now('UTC')->format('Y-m-d-His'),
            Str::lower(Str::random(6)),
        );

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }
}
