<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Contracts;

use Capell\MigrationAssistant\Actions\AssertDatabaseSourceLabelBoundAction;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Exceptions\DatabaseImportFailureException;
use Capell\MigrationAssistant\Exceptions\MissingDatabaseSourceLabelException;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\SafeFailureCause;
use InvalidArgumentException;
use Override;
use Throwable;

abstract class DatabaseImportSessionExecutor implements ImportSessionExecutor
{
    abstract protected function executeDatabase(ImportSession $session, DatabaseSourceManifest $source): void;

    #[Override]
    final public function execute(ImportSession $session): void
    {
        try {
            if (! is_array($session->manifest)) {
                throw new InvalidArgumentException('Invalid database source manifest.');
            }

            $source = DatabaseSourceManifest::fromArray($session->manifest);
            $source->assertUnchanged();
            resolve(AssertDatabaseSourceLabelBoundAction::class)->handle($source);
            $this->executeDatabase($session, $source);
        } catch (Throwable $exception) {
            $reason = $exception instanceof MissingDatabaseSourceLabelException ? MissingDatabaseSourceLabelException::MESSAGE : $exception::class . ': import_failed';
            $cause = $exception instanceof MissingDatabaseSourceLabelException ? null : SafeFailureCause::describe($exception);
            $context = $exception instanceof SafeDatabaseFailureContext ? $exception->safeFailureContext() : null;
            if ($context !== null && (preg_match('/\A[a-z_]+\z/D', $context['entity']) !== 1 || ($context['source_id'] !== 'unknown' && ! ctype_digit($context['source_id'])))) {
                $context = null;
            }
            // Never report the raw exception: a query exception carries its bound row values (emails, password
            // hashes) in its message and trace arguments. Report only the class, the safe cause, the session
            // and the row's source key, with no previous chain.
            report(new DatabaseImportFailureException(
                sprintf(
                    '%s (session %s%s)%s',
                    $reason,
                    is_scalar($session->getKey()) ? (string) $session->getKey() : 'unknown',
                    $context === null ? '' : ', ' . $context['entity'] . ' source key ' . $context['source_id'],
                    $cause === null ? '' : ': ' . $cause,
                ),
                $context,
                $cause,
            ));
            if ($session->exists) {
                try {
                    $session->forceFill(['failure_reason' => $cause === null ? $reason : $reason . ' (' . $cause . ')'])->save();
                } catch (Throwable) {
                    logger()->error('Could not store database import failure classification.');
                }
            }
            throw new DatabaseImportFailureException($reason, $context, $cause);
        }
    }
}
