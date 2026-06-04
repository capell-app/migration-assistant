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
    'rollback_report' => [
        'description' => 'Show the rollback report for a Migration Assistant import session.',
        'not_found' => 'Rollback report for import session [:session] could not be found.',
        'columns' => [
            'model' => 'Model',
            'id' => 'ID',
        ],
    ],
];
