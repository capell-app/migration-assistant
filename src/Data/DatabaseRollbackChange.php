<?php

declare(strict_types=1);

namespace Capell\MigrationAssistant\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class DatabaseRollbackChange
{
    /** @param array<string, mixed>|null $beforeImage */
    public function __construct(public Model $model, public ?array $beforeImage = null, public ?SourceKeyImportResult $source = null) {}
}
