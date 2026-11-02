<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Contracts\SafeDatabaseFailureContext;
use Capell\MigrationAssistant\Contracts\SafeFailureMessage;
use Capell\MigrationAssistant\Exceptions\DatabaseImportFailureException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Describes why an import failed without leaking row values.
 *
 * Exception messages can echo source values (query bindings, parse errors, validation input), so only
 * exceptions marked {@see SafeFailureMessage}, whose messages are fixed translated text, show their
 * message. A query exception is described by its SQLSTATE and driver error code alone. Anything else,
 * which may come from a host contributor or a provider, is named by class.
 */
final class SafeFailureCause
{
    private const int MAX_LENGTH = 300;

    public static function describe(Throwable $exception): string
    {
        $cause = $exception;
        while ($cause instanceof SafeDatabaseFailureContext && $cause->getPrevious() instanceof Throwable) {
            $cause = $cause->getPrevious();
        }
        if ($cause instanceof DatabaseImportFailureException && $cause->cause !== null) {
            return $cause->cause;
        }

        if ($cause instanceof QueryException) {
            $info = is_array($cause->errorInfo) ? $cause->errorInfo : [];
            $sqlState = $info[0] ?? $cause->getCode();
            $driverCode = $info[1] ?? null;

            return $cause::class . ': SQLSTATE ' . (is_string($sqlState) && preg_match('/\A[0-9A-Z]{5}\z/D', $sqlState) === 1 ? $sqlState : 'unknown')
                . ', driver code ' . (is_int($driverCode) ? (string) $driverCode : 'unknown');
        }

        if (! $cause instanceof SafeFailureMessage) {
            return $cause::class;
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $cause->getMessage()));

        return $cause::class . ($message === '' ? '' : ': ' . mb_substr($message, 0, self::MAX_LENGTH));
    }
}
