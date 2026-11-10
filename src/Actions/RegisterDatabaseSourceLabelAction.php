<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Actions;

use Capell\Core\Support\TableName;
use Capell\MigrationAssistant\Exceptions\ImportRefusedException;
use Capell\MigrationAssistant\Support\DatabaseServerIdentity;
use Capell\MigrationAssistant\Support\DatabaseSourceIdentity;
use Illuminate\Support\Facades\DB;

final class RegisterDatabaseSourceLabelAction
{
    public function handle(string $label, string $connection, bool $repoint, bool $newSource, ?string $destinationConnection = null): void
    {
        // Bind the label to the live server, not to config that could be repointed at another database.
        $identity = DatabaseServerIdentity::forConnection($connection);
        $tuple = json_encode(DatabaseSourceIdentity::tuple($connection), JSON_THROW_ON_ERROR);
        $destination = DB::connection($destinationConnection);
        $destination->transaction(static function () use ($destination, $label, $connection, $identity, $tuple, $repoint, $newSource): void {
            if ($destination->table(TableName::resolve('database_source_label_locks'))->where('id', 1)->lockForUpdate()->first() === null) {
                throw new ImportRefusedException('The source registry lock is unavailable.');
            }

            $record = $destination->table(TableName::resolve('database_source_labels'))->where('label', $label)->lockForUpdate()->first();
            // A matching server identity is not enough: a repoint from staging to production with the same server id
            // and port must still be refused, so the stored host, port and database are compared as well.
            $sameServer = $record !== null && $record->connection_identity === $identity;
            $sameTuple = $record !== null && DatabaseServerIdentity::tupleMatches($record->connection_tuple, $connection);
            if ($sameServer && $sameTuple) {
                return;
            }

            if ($record !== null && ! $repoint) {
                throw new ImportRefusedException((string) __('migration-assistant::imports.source_label_repointed', ['label' => $label]));
            }

            $used = $destination->table(TableName::resolve('database_source_labels'))->where('connection_identity', $identity)->where('label', '!=', $label)->lockForUpdate()->exists();
            if ($used && ! $newSource) {
                throw new ImportRefusedException((string) __('migration-assistant::imports.source_label_shared'));
            }

            if ($record === null) {
                $destination->table(TableName::resolve('database_source_labels'))->insert(['label' => $label, 'connection_identity' => $identity, 'connection_tuple' => $tuple, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $destination->table(TableName::resolve('database_source_labels'))->where('label', $label)->update(['connection_identity' => $identity, 'connection_tuple' => $tuple, 'updated_at' => now()]);
            }
        });
    }
}
