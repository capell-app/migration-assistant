<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Support;

use Capell\MigrationAssistant\Enums\InPlaceDatabaseRefusalReason;
use Closure;
use Throwable;

final class DatabaseSessionLockChallenge
{
    /**
     * @param  Closure(string, list<mixed>): mixed  $logical
     * @param  Closure(string, list<mixed>): mixed  $reader
     * @param  Closure(string, list<mixed>): mixed  $writer
     */
    public function handle(Closure $logical, Closure $reader, Closure $writer): void
    {
        $name = 'capell-inplace-' . bin2hex(random_bytes(24));
        $acquired = false;
        try {
            $owner = $this->connectionId($logical('SELECT CONNECTION_ID()', []));
            if ($owner === null) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The physical database session challenge is unavailable.');
            }
            $acquisition = $logical('SELECT GET_LOCK(?, 0)', [$name]);
            if (! in_array($acquisition, [1, '1'], true)) {
                throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The physical database session challenge is unavailable.');
            }
            $acquired = true;
            foreach ([$reader, $writer] as $observe) {
                if ($this->connectionId($observe('SELECT IS_USED_LOCK(?)', [$name])) !== $owner) {
                    throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The actual PDO sessions do not share one database server.');
                }
            }
        } catch (Throwable) {
            throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The actual PDO sessions could not prove a shared database server.');
        } finally {
            if ($acquired) {
                try {
                    $released = $logical('SELECT RELEASE_LOCK(?)', [$name]);
                    if (! in_array($released, [1, '1'], true) || $logical('SELECT IS_USED_LOCK(?)', [$name]) !== null) {
                        throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The physical database challenge could not be cleaned up.');
                    }
                } catch (Throwable) {
                    throw InPlaceDatabaseRefusalReason::ProfileMismatch->exception('The physical database challenge could not be cleaned up.');
                }
            }
        }
    }

    private function connectionId(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[0-9]+\z/D', (string) $value) !== 1) {
            return null;
        }
        $id = ltrim((string) $value, '0');

        return $id === '' ? null : $id;
    }
}
