<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Exceptions;

use Capell\MigrationAssistant\Contracts\SafeFailureMessage;
use RuntimeException;

final class ImportExecutionAuthorizationException extends RuntimeException implements SafeFailureMessage {}
