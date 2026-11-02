<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

/**
 * Marks an exception whose message is fixed (translated) text that never embeds a source value,
 * so a failure description may show it. Every other exception is named by class alone.
 */
interface SafeFailureMessage {}
