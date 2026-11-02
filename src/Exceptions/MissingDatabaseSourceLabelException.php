<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Exceptions;

use Capell\MigrationAssistant\Contracts\SafeFailureMessage;
use RuntimeException;

final class MissingDatabaseSourceLabelException extends RuntimeException implements SafeFailureMessage
{
    public const string MESSAGE = 'Re-run preview with --source-label to create a new database import session.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
