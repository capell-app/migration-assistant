<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Health\MigrationAssistantHealthCheck;

it('keeps diagnostic identities while naming checks by package and purpose', function (): void {
    $results = MigrationAssistantHealthCheck::runDiagnostics();
    $existingIds = [
        'legacy.migration.assistant.package.reader',
        'legacy.migration.assistant.rollback.reports',
        'legacy.migration.assistant.media.ingest',
        'legacy.migration.assistant.storage.tables',
        'legacy.migration.assistant.model.morph.aliases',
        'legacy.migration.assistant.source.readers',
        'legacy.migration.assistant.media.ingest.limit',
    ];

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result->label)->toStartWith('Migration Assistant: ')
            ->not->toMatch('/morph|contributor|registry|binding|runtime surfaces/i');
        expect($existingIds)->toContain($result->id);
    }
});
