<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Exceptions;

use RuntimeException;

final class DatabaseImportFailureException extends RuntimeException
{
    /** @param array{entity: string, source_id: string}|null $context */
    public function __construct(string $reason, public readonly ?array $context = null, public readonly ?string $cause = null)
    {
        parent::__construct($reason);
    }

    /** The reason with the safe underlying cause, for storing on the session. */
    public function failureReason(): string
    {
        return $this->cause === null ? $this->getMessage() : $this->getMessage() . ' (' . $this->cause . ')';
    }
}
