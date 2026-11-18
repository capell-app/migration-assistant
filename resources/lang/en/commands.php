<?php

declare(strict_types=1);

return [
    'install' => [
        'permissions_installed' => 'Migration Assistant permissions are installed. Staff roles can now be given import, export and rollback access.',
    ],
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
        'completed' => 'Stale import sessions requeued: :count.',
    ],
    'export' => [
        'description' => 'Export a Capell migration package from page or site ids.',
        'require_scope' => 'Provide either one or more --page ids or one or more --site ids.',
        'completed' => 'Migration package exported to :path.',
    ],
    'import' => [
        'description' => 'Create, validate, and optionally execute a Migration Assistant import session.',
        'archive_not_found' => 'The migration package archive could not be found.',
        'archive_too_large' => 'The migration package is larger than the :size MB limit set by migration-assistant.limits.max_package_upload_kilobytes, so nothing was imported.',
        'invalid_kind' => 'Import kind must be page-import or site-import.',
        'session_not_found' => 'The import session could not be created.',
        'actor_required' => "Name the user the import runs as with --actor=<user id>. Executing an import writes pages, so it is checked against that user's import permission, and nothing was imported.",
        'actor_not_found' => 'No user matches the --actor ID, so nothing was imported. Check the ID and run the command again.',
        'actor_denied' => 'The --actor user is not allowed to import pages, so nothing was imported. Give the user the page import permission or name another user.',
        'completed' => 'Import session :session is :status.',
    ],
    'rollback_report' => [
        'description' => 'Show the rollback report for a Migration Assistant import session.',
        'not_found' => 'Rollback report for import session [:session] could not be found.',
        'columns' => [
            'model' => 'Model',
            'id' => 'ID',
            'type' => 'Record',
            'site' => 'Site',
            'operation' => 'Change',
        ],
        'global' => 'Global',
    ],
    'rollback_execute' => [
        'description' => 'Execute a signed Migration Assistant rollback report as an authorised actor.',
        'actor_required' => 'A valid --actor user ID is required to authorise rollback execution.',
        'dry_run' => 'Dry run only; no imported records were deleted or restored.',
        'not_found' => 'Rollback report for import session [:session] could not be found.',
        'rejected' => 'Rollback execution was rejected because its provenance or actor authorisation is invalid.',
        'delete_failed' => 'Some records could not be removed and were kept. Ask an authorised administrator to review the rollback report and application log before manual recovery.',
        'summary' => 'Imported records matched: :matched. Deleted: :deleted. Restored: :restored. Skipped: :skipped.',
        'not_dedicated' => 'This import did not run in a dedicated process, so roll it back without --dedicated.',
        'dedicated_refused' => 'The dedicated rollback process was not started, and nothing was changed: :cause',
    ],
];
