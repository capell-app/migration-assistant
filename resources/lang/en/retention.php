<?php

declare(strict_types=1);

return [
    'description' => 'Preview import evidence retention and storage; use --prune to delete eligible evidence.',
    'report' => ':mode (:days days): :sessions sessions, :bytes archive bytes; :eligible eligible sessions, :eligible_bytes eligible bytes; :pruned pruned, :failed failed.',
    'preview' => 'Preview',
    'prune' => 'Prune',
    'eligible' => 'Eligible session: :id',
    'failed' => 'Session :id could not be processed; evidence retained. Check the application log before retrying.',
    'hold' => 'Place legal hold',
    'release' => 'Release legal hold',
];
