<?php

declare(strict_types=1);

use Capell\MigrationAssistant\Console\Commands\PruneImportEvidenceCommand;

it('declares the retention command in both manifest command surfaces', function (): void {
    $json = file_get_contents(dirname(__DIR__, 2) . '/capell.json');
    if (! is_string($json)) {
        throw new RuntimeException('Package manifest is missing.');
    }

    $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest)) {
        throw new UnexpectedValueException('Expected a manifest object.');
    }

    $commands = $manifest['commands'] ?? null;
    $contributions = $manifest['contributes'] ?? null;
    if (! is_array($commands) || ! is_array($contributions)) {
        throw new UnexpectedValueException('Expected command and contribution declarations.');
    }

    expect($commands['prune'] ?? null)->toBe('migration-assistant:prune');
    $declarations = 0;
    foreach ($contributions as $contribution) {
        if (! is_array($contribution) || ($contribution['type'] ?? null) !== 'console-command') {
            continue;
        }

        expect($contribution['commands'] ?? [])->toContain('migration-assistant:prune')
            ->and($contribution['commandClasses'] ?? [])->toContain(PruneImportEvidenceCommand::class);
        $declarations++;
    }

    expect($declarations)->toBe(1);
});
