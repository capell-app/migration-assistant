<?php

declare(strict_types=1);

return [
    'expected_package_type' => 'Expected a :expected package for :kind; got :actual.',
    'health' => [
        'none' => 'none',
        'ok' => 'ok',
        'failed_status' => 'failed',
        'package_reader' => [
            'label' => 'Migration Assistant package reader',
            'passed' => 'Package reader services, source readers, manifest validation, and size limits are ready.',
            'failed' => 'Package reader is not ready. Missing services: :services. Missing tables: :tables. Invalid limits: :limits. Unsupported source readers: :extensions. Manifest validation: :manifest.',
            'remediation' => 'Ensure MigrationAssistantServiceProvider registers the package reader, manifest validator, CSV/XML readers, and positive package size limits.',
        ],
        'rollback_report' => [
            'label' => 'Migration Assistant rollback reports',
            'passed' => 'Rollback report storage and model morph aliases are ready.',
            'failed' => 'Rollback report support is not ready. Missing tables: :tables. Unregistered morph aliases: :aliases.',
            'remediation' => 'Run the Migration Assistant migrations and ensure the rollback report model is registered with Capell.',
        ],
        'media_ingest' => [
            'label' => 'Migration Assistant media ingest',
            'passed' => 'A positive per-file media size limit is configured for checksum-verified media ingest.',
            'failed' => 'No positive media size limit is configured; media ingest cannot enforce per-file limits.',
            'remediation' => 'Set migration-assistant.limits.max_media_bytes to a positive byte count.',
        ],
        'storage_tables' => [
            'label' => 'Migration Assistant storage tables',
            'passed' => 'The import session and rollback report tables are present.',
            'failed' => 'Missing tables: :tables.',
            'remediation' => 'Run the Capell migrations to create the Migration Assistant storage tables.',
        ],
        'morph_aliases' => [
            'label' => 'Migration Assistant model morph aliases',
            'passed' => 'Import session and rollback report models are registered in the morph map.',
            'failed' => 'Unregistered morph aliases: :aliases.',
            'remediation' => 'Ensure MigrationAssistantServiceProvider registers the import models.',
        ],
        'source_readers' => [
            'label' => 'Migration Assistant source readers',
            'passed' => 'Source readers are registered for the CSV and XML formats.',
            'failed' => 'No source reader is registered for: :extensions.',
            'remediation' => 'Ensure MigrationAssistantServiceProvider registers the CSV and XML source readers.',
        ],
        'media_limit' => [
            'label' => 'Migration Assistant media ingest limit',
            'passed' => 'A positive per-file media size limit is configured for media ingest.',
            'failed' => 'No positive media size limit is configured; media ingest cannot enforce per-file limits.',
            'remediation' => 'Set migration-assistant.limits.max_media_bytes to a positive byte count.',
        ],
    ],
];
