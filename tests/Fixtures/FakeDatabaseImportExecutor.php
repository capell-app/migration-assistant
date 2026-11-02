<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Tests\Fixtures;

use Capell\MigrationAssistant\Actions\CreateDatabaseRollbackReportAction;
use Capell\MigrationAssistant\Contracts\DatabaseImportSessionExecutor;
use Capell\MigrationAssistant\Data\DatabaseRollbackChange;
use Capell\MigrationAssistant\Data\DatabaseSourceManifest;
use Capell\MigrationAssistant\Models\ImportRollbackReport;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\MigrationAssistant\Support\SourceKeyImporter;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;

final class FakeDatabaseImportExecutor extends DatabaseImportSessionExecutor
{
    public string $outcome = '';

    public bool $force = false;

    public ?ImportRollbackReport $report = null;

    #[Override]
    public function supports(ImportSession $session): bool
    {
        return $session->source_environment === 'fake-db';
    }

    #[Override]
    public function canRetry(ImportSession $session): bool
    {
        return true;
    }

    #[Override]
    protected function executeDatabase(ImportSession $session, DatabaseSourceManifest $source): void
    {
        DB::transaction(function () use ($session, $source): void {
            $row = DB::connection($source->connection)->table('fake_import_rows')->first();
            if ($row === null || ! is_int($row->id) || ! is_string($row->name)) {
                throw new LogicException('Expected a source row.');
            }
            $result = resolve(SourceKeyImporter::class)->import(
                $session,
                'user',
                (string) $row->id,
                ['name' => $row->name],
                User::class,
                static function (User $user, array $values): User {
                    if (! $user->exists) {
                        $user->forceFill(User::factory()->make()->getAttributes());
                    }
                    $user->forceFill(['name' => $values['name']])->save();

                    return $user->refresh();
                },
                force: $this->force,
            );
            $this->outcome = $result->outcome;
            if (in_array($result->outcome, ['created', 'updated'], true)) {
                $target = User::query()->findOrFail($result->targetId);
                $this->report = CreateDatabaseRollbackReportAction::run($session, [new DatabaseRollbackChange($target, $result->beforeImage, $result)]);
            }
        });
    }
}
