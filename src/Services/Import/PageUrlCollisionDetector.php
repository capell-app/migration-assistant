<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Services\Import;

use Capell\MigrationAssistant\Contracts\PageCollisionDetector;
use Capell\MigrationAssistant\Data\PageReviewRow;
use Illuminate\Support\Facades\DB;

/**
 * Queries the page_urls table to detect live URL conflicts. Packages with
 * staged draft contexts can replace this binding with a richer detector.
 */
final class PageUrlCollisionDetector implements PageCollisionDetector
{
    public function detect(array $urls, ?int $resolvedSiteId): array
    {
        foreach ($urls as $urlData) {
            $url = $urlData['url'];
            $siteId = $urlData['site_id'] ?? $resolvedSiteId;
            $languageId = $urlData['language_id'] ?? null;

            $query = DB::table('page_urls')
                ->where('url', $url)
                ->whereNull('deleted_at');

            if ($siteId !== null) {
                $query->where('site_id', $siteId);
            }

            if ($languageId !== null) {
                $query->where('language_id', $languageId);
            }

            if ($query->exists()) {
                return [
                    PageReviewRow::COLLISION_URL_LIVE,
                    [sprintf('URL "%s" already exists on a live page.', $url)],
                    PageReviewRow::ACTION_UPDATE,
                ];
            }
        }

        return [PageReviewRow::COLLISION_NONE, [], PageReviewRow::ACTION_CREATE];
    }
}
