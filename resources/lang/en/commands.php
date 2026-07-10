<?php

declare(strict_types=1);

return [
    'status' => [
        'description' => 'Show Migration Assistant import session status.',
        'not_found' => 'Import session [:session] could not be found.',
        'empty' => 'No import sessions found.',
        'columns' => [
            'id' => 'ID',
            'uuid' => 'UUID',
            'kind' => 'Kind',
            'status' => 'Status',
            'source' => 'Source',
            'executed_at' => 'Executed at',
        ],
    ],
    'reclaim_stale' => [
        'description' => 'Requeue import sessions abandoned by terminated workers.',
        'completed' => 'Requeued :count stale import session(s).',
    ],
    'export' => [
        'description' => 'Export a Capell migration package from page or site ids.',
        'require_scope' => 'Provide either one or more --page ids or one or more --site ids.',
        'completed' => 'Migration package exported to :path.',
    ],
    'import' => [
        'description' => 'Create, validate, and optionally execute a Migration Assistant import session.',
        'archive_not_found' => 'The migration package archive could not be found.',
        'invalid_kind' => 'Import kind must be page-import or site-import.',
        'session_not_found' => 'The import session could not be created.',
        'completed' => 'Import session :session is :status.',
    ],
    'rollback_report' => [
        'description' => 'Show the rollback report for a Migration Assistant import session.',
        'not_found' => 'Rollback report for import session [:session] could not be found.',
        'columns' => [
            'model' => 'Model',
            'id' => 'ID',
        ],
    ],
    'rollback_execute' => [
        'description' => 'Execute a signed Migration Assistant rollback report as an authorized actor.',
        'actor_required' => 'A valid --actor user ID is required to authorize rollback execution.',
        'dry_run' => 'Dry run only; no imported records were deleted.',
        'not_found' => 'Rollback report for import session [:session] could not be found.',
        'summary' => 'Matched :matched imported record(s), deleted :deleted, skipped :skipped.',
    ],
];
