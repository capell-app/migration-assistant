<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Support\DatabaseServerIdentity;
use Illuminate\Support\Facades\DB;

/**
 * Refuses to run a prepared session when its label has since been repointed to another database server.
 */
final class AssertDatabaseSourceLabelBoundAction
{
    public function handle(DatabaseSourceManifest $source): void
    {
        $record = DB::connection($source->writePlan?->writerConnection)->table(TableName::resolve('database_source_labels'))->where('label', $source->sourceLabel)->first();
        if ($record === null) {
            throw new ImportRefusedException((string) __('migration-assistant::imports.source_label_unregistered', ['label' => $source->sourceLabel]));
        }

        if (! is_string($record->connection_identity) || ! hash_equals($record->connection_identity, $source->connectionIdentity) || ! DatabaseServerIdentity::tupleMatches($record->connection_tuple ?? null, $source->connection)) {
            throw new ImportRefusedException((string) __('migration-assistant::imports.source_label_moved', ['label' => $source->sourceLabel]));
        }
    }
}
