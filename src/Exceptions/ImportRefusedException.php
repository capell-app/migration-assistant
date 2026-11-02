<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Exceptions;

use Capell\MigrationAssistant\Contracts\SafeFailureMessage;
use LogicException;

/** A refusal with fixed, translated text, safe to show in a failure description. */
final class ImportRefusedException extends LogicException implements SafeFailureMessage {}
