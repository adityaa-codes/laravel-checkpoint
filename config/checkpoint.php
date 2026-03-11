<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;

/** @phpstan-ignore-next-line */
$env = static fn (string $key, mixed $default = null): mixed => env($key, $default);

return [
    'user_model' => $env('DB_OPS_USER_MODEL', 'App\\Models\\User'),
    'user_name_column' => $env('DB_OPS_USER_NAME_COLUMN', 'name'),
    'table_prefix' => $env('DB_OPS_TABLE_PREFIX', 'db_ops_'),

    'queue' => [
        'connection' => $env('DB_OPS_QUEUE_CONNECTION'),
        'name' => $env('DB_OPS_QUEUE_NAME', 'db-ops'),
        'max_attempts' => (int) $env('DB_OPS_QUEUE_MAX_ATTEMPTS', 1),
        'retry_after' => (int) $env('DB_OPS_QUEUE_RETRY_AFTER', 90),
        'timeout' => (int) $env('DB_OPS_QUEUE_TIMEOUT', 3600),
        'orphan_threshold' => (int) $env('DB_OPS_QUEUE_ORPHAN_THRESHOLD', 10),
    ],

    'schedule' => [
        'logical_backup_enabled' => (bool) $env('DB_OPS_BACKUP_SCHEDULE_ENABLED', true),
        'logical_backup_daily_at' => $env('DB_OPS_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => $env('DB_OPS_BACKUP_TIMEZONE', 'UTC'),
        'health_check_enabled' => (bool) $env('DB_OPS_HEALTH_CHECK_ENABLED', true),
        'recover_orphans_enabled' => (bool) $env('DB_OPS_RECOVER_ORPHANS_ENABLED', true),
        'prune_enabled' => (bool) $env('DB_OPS_PRUNE_ENABLED', true),
        'prune_keep_days' => (int) $env('DB_OPS_PRUNE_KEEP_DAYS', 90),
        'prune_keep_failed_days' => (int) $env('DB_OPS_PRUNE_KEEP_FAILED_DAYS', 365),
    ],

    'driver' => $env('DB_OPS_DRIVER', 'shell'),
    'drivers' => [
        'shell' => [
            'class' => ShellCommandDriver::class,
            'commands' => [
                'logical_backup' => $env('DB_OPS_CMD_LOGICAL_BACKUP', ''),
                'logical_restore_latest' => $env('DB_OPS_CMD_RESTORE_LATEST', ''),
                'logical_restore_file' => $env('DB_OPS_CMD_RESTORE_FILE', ''),
                'pitr_restore' => $env('DB_OPS_CMD_PITR_RESTORE', ''),
                'backup_drill' => $env('DB_OPS_CMD_BACKUP_DRILL', ''),
                'pgbackrest_check' => $env('DB_OPS_CMD_PGBACKREST_CHECK', ''),
                'pgbackrest_info' => $env('DB_OPS_CMD_PGBACKREST_INFO', ''),
            ],
            'pgbackrest_stanza' => $env('DB_OPS_PGBACKREST_STANZA', 'main'),
            'backup_dir' => $env('DB_OPS_BACKUP_DIR', storage_path('db-backups')),
            'backup_prefix' => $env('DB_OPS_BACKUP_PREFIX', 'backup'),
            'pre_restore_snapshot' => (bool) $env('DB_OPS_PRE_RESTORE_SNAPSHOT', true),
            'command_timeout_seconds' => (int) $env('DB_OPS_CMD_TIMEOUT', 7200),
        ],
    ],

    'log_channel' => $env('DB_OPS_LOG_CHANNEL', 'stack'),
    'custom_operations' => [],
];
