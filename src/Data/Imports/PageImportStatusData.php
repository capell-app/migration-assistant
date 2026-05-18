<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data\Imports;

use Spatie\LaravelData\Data;

final class PageImportStatusData extends Data
{
    public const string NOTICE_SUMMARY_BLOCKING_ERRORS = 'summary_blocking_errors';

    public const string NOTICE_CONFIRMATION_MISMATCH = 'confirmation_mismatch';

    public const string NOTICE_IMPORT_QUEUED = 'import_queued';

    /**
     * @param  array<string, mixed>  $resultSummary
     */
    public function __construct(
        public readonly string $step,
        public readonly ?string $sessionStatus = null,
        public readonly array $resultSummary = [],
        public readonly ?string $failureReason = null,
        public readonly ?int $targetId = null,
        public readonly ?string $targetUrl = null,
        public readonly ?string $notice = null,
        public readonly ?string $noticeBody = null,
    ) {}
}
