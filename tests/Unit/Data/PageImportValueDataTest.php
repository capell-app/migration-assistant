<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Unit\Data;

use Capell\MigrationAssistant\Data\Imports\PageImportStatusData;
use Capell\MigrationAssistant\Data\Imports\PageImportWizardStateData;
use PHPUnit\Framework\TestCase;

final class PageImportValueDataTest extends TestCase
{
    public function test_page_import_status_exposes_stable_notice_constants_and_payload_state(): void
    {
        $status = new PageImportStatusData(
            step: 'validate',
            sessionStatus: 'Failed',
            resultSummary: ['blocking_errors' => 2],
            failureReason: 'Checksum mismatch',
            targetId: 42,
            targetUrl: 'https://example.test/admin/workspaces/42',
            notice: PageImportStatusData::NOTICE_SUMMARY_BLOCKING_ERRORS,
            noticeBody: 'Fix validation errors before dispatching.',
        );

        $this->assertSame('confirmation_mismatch', PageImportStatusData::NOTICE_CONFIRMATION_MISMATCH);
        $this->assertSame('import_queued', PageImportStatusData::NOTICE_IMPORT_QUEUED);
        $this->assertSame('validate', $status->step);
        $this->assertSame('Failed', $status->sessionStatus);
        $this->assertSame(['blocking_errors' => 2], $status->resultSummary);
        $this->assertSame('Checksum mismatch', $status->failureReason);
        $this->assertSame(42, $status->targetId);
        $this->assertSame('https://example.test/admin/workspaces/42', $status->targetUrl);
        $this->assertSame('summary_blocking_errors', $status->notice);
        $this->assertSame('Fix validation errors before dispatching.', $status->noticeBody);
    }

    public function test_page_import_wizard_state_carries_review_and_validation_state(): void
    {
        $state = new PageImportWizardStateData(
            step: 'resolve',
            sessionId: 7,
            reviewRows: [['title' => 'Imported page']],
            pageDecisions: ['page-1' => ['action' => 'create']],
            resolveRows: [['key' => 'author:ben']],
            relationDecisions: ['author:ben' => ['action' => 'use_existing', 'target_id' => 9]],
            validationSummary: ['warnings' => 1],
            confirmationExpected: 'IMPORT 1 PAGE',
            notice: PageImportWizardStateData::NOTICE_UNRESOLVED_REFERENCES,
            noticeCount: 1,
        );

        $this->assertSame('blocked_by_workspace_conflict', PageImportWizardStateData::NOTICE_BLOCKED_BY_WORKSPACE_CONFLICT);
        $this->assertSame('blocked_pending_decisions', PageImportWizardStateData::NOTICE_BLOCKED_PENDING_DECISIONS);
        $this->assertSame('resolve', $state->step);
        $this->assertSame(7, $state->sessionId);
        $this->assertCount(1, $state->reviewRows);
        $this->assertSame('create', $state->pageDecisions['page-1']['action']);
        $this->assertCount(1, $state->resolveRows);
        $this->assertSame(9, $state->relationDecisions['author:ben']['target_id']);
        $this->assertSame(['warnings' => 1], $state->validationSummary);
        $this->assertSame('IMPORT 1 PAGE', $state->confirmationExpected);
        $this->assertSame('unresolved_references', $state->notice);
        $this->assertSame(1, $state->noticeCount);
    }
}
