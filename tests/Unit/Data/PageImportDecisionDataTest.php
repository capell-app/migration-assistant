<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Unit\Data;

use Capell\MigrationAssistant\Data\Imports\PageImportDecisionData;
use PHPUnit\Framework\TestCase;

final class PageImportDecisionDataTest extends TestCase
{
    public function test_it_skips_resolve_when_no_relation_rows_exist(): void
    {
        $decision = new PageImportDecisionData(
            sessionId: 12,
            reviewRows: [],
            pageDecisions: [],
            resolveRows: [],
            relationDecisions: [],
            canUpdateSharedRelations: true,
        );

        $this->assertTrue($decision->shouldSkipResolveStep());
    }

    public function test_it_skips_resolve_when_every_relation_row_has_one_unambiguous_match(): void
    {
        $decision = new PageImportDecisionData(
            sessionId: 12,
            reviewRows: [],
            pageDecisions: [],
            resolveRows: [
                ['key' => 'author:ben', 'top_match' => ['id' => 5], 'alternatives' => []],
                ['key' => 'tag:news', 'top_match' => ['id' => 9]],
            ],
            relationDecisions: [],
            canUpdateSharedRelations: true,
        );

        $this->assertTrue($decision->shouldSkipResolveStep());
    }

    public function test_it_requires_resolve_when_a_relation_row_has_no_top_match(): void
    {
        $decision = new PageImportDecisionData(
            sessionId: 12,
            reviewRows: [],
            pageDecisions: [],
            resolveRows: [
                ['key' => 'author:missing', 'top_match' => null, 'alternatives' => []],
            ],
            relationDecisions: [],
            canUpdateSharedRelations: true,
        );

        $this->assertFalse($decision->shouldSkipResolveStep());
    }

    public function test_it_requires_resolve_when_a_relation_row_has_alternatives(): void
    {
        $decision = new PageImportDecisionData(
            sessionId: 12,
            reviewRows: [],
            pageDecisions: [],
            resolveRows: [
                [
                    'key' => 'author:ben',
                    'top_match' => ['id' => 5],
                    'alternatives' => [['id' => 6]],
                ],
            ],
            relationDecisions: [],
            canUpdateSharedRelations: true,
        );

        $this->assertFalse($decision->shouldSkipResolveStep());
    }
}
