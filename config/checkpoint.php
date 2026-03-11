<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use Illuminate\Foundation\Auth\User;

/** @phpstan-ignore-next-line */
$env = static fn (string $key, mixed $default = null): mixed => env($key, $default);

return [
    'user_model' => $env('DB_OPS_USER_MODEL', User::class),
    'user_name_column' => $env('DB_OPS_USER_NAME_COLUMN', 'name'),
    'table_prefix' => $env('DB_OPS_TABLE_PREFIX', 'db_ops_'),

    'queue' => [
        'connection' => $env('DB_OPS_QUEUE_CONNECTION'),
        'name' => $env('DB_OPS_QUEUE_NAME', 'db-ops'),
        'max_attempts' => (int) $env('DB_OPS_QUEUE_MAX_ATTEMPTS', 1),
        'retry_after' => (int) $env('DB_OPS_QUEUE_RETRY_AFTER', 3660),
        'timeout' => (int) $env('DB_OPS_QUEUE_TIMEOUT', 3600),
        'orphan_threshold' => (int) $env('DB_OPS_QUEUE_ORPHAN_THRESHOLD', 10),
        'unique_for' => (int) $env('DB_OPS_QUEUE_UNIQUE_FOR', 3660),
        'lock_store' => $env('DB_OPS_QUEUE_LOCK_STORE'),
    ],

    'schedule' => [
        'logical_backup_enabled' => (bool) $env('DB_OPS_BACKUP_SCHEDULE_ENABLED', true),
        'logical_backup_daily_at' => $env('DB_OPS_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => $env('DB_OPS_BACKUP_TIMEZONE', 'UTC'),
        'health_check_enabled' => (bool) $env('DB_OPS_HEALTH_CHECK_ENABLED', true),
        'recover_orphans_enabled' => (bool) $env('DB_OPS_RECOVER_ORPHANS_ENABLED', true),
        'prune_enabled' => (bool) $env('DB_OPS_PRUNE_ENABLED', true),
        'without_overlapping' => (bool) $env('DB_OPS_SCHEDULE_WITHOUT_OVERLAPPING', true),
        'overlap_expires_at' => (int) $env('DB_OPS_SCHEDULE_OVERLAP_EXPIRES_AT', 180),
        'on_one_server' => (bool) $env('DB_OPS_SCHEDULE_ON_ONE_SERVER', true),
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
        'pgbackrest' => [
            'class' => PgBackRestDriver::class,
            'binary' => $env('DB_OPS_PGBACKREST_BINARY', 'pgbackrest'),
            'stanza' => $env('DB_OPS_PGBACKREST_STANZA', 'main'),
            'repo' => (int) $env('DB_OPS_PGBACKREST_REPO', 1),
            'process_max' => (int) $env('DB_OPS_PGBACKREST_PROCESS_MAX', 2),
            'resume' => (bool) $env('DB_OPS_PGBACKREST_RESUME', true),
            'start_fast' => (bool) $env('DB_OPS_PGBACKREST_START_FAST', true),
            'backup_standby' => (bool) $env('DB_OPS_PGBACKREST_BACKUP_STANDBY', false),
            'checksum_page' => (bool) $env('DB_OPS_PGBACKREST_CHECKSUM_PAGE', false),
            'delta' => (bool) $env('DB_OPS_PGBACKREST_DELTA', false),
            'command_timeout_seconds' => (int) $env('DB_OPS_PGBACKREST_TIMEOUT', 7200),
            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'verify' => [],
                'check' => [],
                'info' => [],
            ],
        ],
        'pgdump' => [
            'class' => PgDumpDriver::class,
            'dump_binary' => $env('DB_OPS_PGDUMP_BINARY', 'pg_dump'),
            'restore_binary' => $env('DB_OPS_PGRESTORE_BINARY', 'pg_restore'),
            'format' => $env('DB_OPS_PGDUMP_FORMAT', 'directory'),
            'jobs' => (int) $env('DB_OPS_PGDUMP_JOBS', 4),
            'compress_level' => (int) $env('DB_OPS_PGDUMP_COMPRESS_LEVEL', 6),
            'output_dir' => $env('DB_OPS_PGDUMP_OUTPUT_DIR', storage_path('app/checkpoint/logical-exports')),
            'output_prefix' => $env('DB_OPS_PGDUMP_OUTPUT_PREFIX', 'logical-export'),
            'file_extension' => $env('DB_OPS_PGDUMP_FILE_EXTENSION', 'dump'),
            'clean' => (bool) $env('DB_OPS_PGDUMP_RESTORE_CLEAN', true),
            'create' => (bool) $env('DB_OPS_PGDUMP_RESTORE_CREATE', false),
            'command_timeout_seconds' => (int) $env('DB_OPS_PGDUMP_TIMEOUT', 7200),
            'extra_args' => [
                'backup' => [],
                'restore' => [],
            ],
        ],
    ],

    'log_channel' => $env('DB_OPS_LOG_CHANNEL', 'stack'),
    'custom_operations' => [],
];
