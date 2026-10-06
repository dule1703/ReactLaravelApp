<?php

return [
    // Logs older than this are removed by `php artisan activitylog:prune`.
    'retention_days' => (int) env('ACTIVITY_LOG_RETENTION_DAYS', 365),

    // Values of these fields are NEVER stored, only the field name. Hidden model attributes
    // ($hidden) are treated the same way. Add every new sensitive column here.
    // `note` is the free-text note of an offer (it may hold personal data).
    'sensitive' => ['password', 'remember_token', 'jmbg', 'jmbg_hash', 'pib', 'client_pib', 'issuer_pib', 'note', 'token', 'secret'],

    // Fields that are not worth logging; an update touching only these is not logged at all.
    'ignored' => ['updated_at', 'remember_token'],
];
