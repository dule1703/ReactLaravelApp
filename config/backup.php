<?php

return [
    // The schedule (routes/console.php) registers the backups only when this is true. On by default
    // for a MySQL / MariaDB connection; local SQLite development has nothing to dump.
    'enabled' => filter_var(
        env('BACKUP_ENABLED', in_array(env('DB_CONNECTION', 'sqlite'), ['mysql', 'mariadb'], true)),
        FILTER_VALIDATE_BOOLEAN,
    ),

    // The database connection to dump (empty = the default one). Only the tests set it.
    'connection' => null,

    // Where the backups are written: shared/storage/app/backups on the server. Never inside the public
    // disk (storage/app/public) or public/: the folder must not be reachable from the web.
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    // The cron job has a minimal PATH, so the server sets the full path (e.g. /bin/mysqldump).
    'mysqldump' => env('BACKUP_MYSQLDUMP') ?: 'mysqldump',

    // How many backups to keep (the newest ones; older ones are deleted after a SUCCESSFUL new backup).
    'db_keep' => (int) env('BACKUP_DB_KEEP', 14),
    'files_keep' => (int) env('BACKUP_FILES_KEEP', 4),

    // A dump smaller than this (uncompressed) is a failure: even an empty schema is bigger.
    'min_dump_bytes' => 1024,

    // Seconds the dump may run.
    'timeout' => 600,
];
