<?php

declare(strict_types=1);

return [

    'driver' => env('DB_OPS_DRIVER', 'shell'),

    'destination' => [
        'disks' => [],
    ],

    'retention_days' => 30,

    'encryption' => [
        'enabled' => false,
    ],

    'queue' => [
        'name' => env('DB_OPS_QUEUE_NAME', 'db-ops'),
        'timeout' => (int) env('DB_OPS_QUEUE_TIMEOUT', 3600),
        'lock_store' => null,
        'unique_for' => 3660,
        'max_attempts' => 1,
        'orphan_threshold' => 10,
    ],

    'restore' => [
        'allowed_environments' => ['local', 'testing', 'staging'],
        'allowed_databases' => [],
        'allow_in_ci' => false,
        'require_verified_backup' => false,
    ],

    'gates' => [
        'override_profile' => null,
        'default_profile' => 'production',
        'environment_profile_map' => [],
        'code_map' => [],
        'profiles' => [
            'local' => ['blocker' => 0, 'warning' => 0, 'advisory' => 0],
            'staging' => ['blocker' => 0, 'warning' => 0, 'advisory' => 0],
            'production' => ['blocker' => 0, 'warning' => 0, 'advisory' => 0],
        ],
    ],

    'observability' => [
        'backup_drill_pass_rate_window_days' => 30,
        'backup_drill_min_pass_rate' => 100.0,
        'max_backup_drill_age_days' => 30,
        'max_last_known_good_age_hours' => 24,
        'backup_duration_min_samples' => 3,
        'backup_duration_anomaly_factor' => 2.0,
        'alert_cooldown_seconds' => 300,
    ],

    'reporting' => [
        'max_recent_runs' => 50,
    ],

    'output' => [
        'max_persisted_bytes' => 1048576,
        'storage' => 'database',
        'filesystem' => [
            'disk' => 'local',
            'path_prefix' => 'checkpoint/command-output',
            'inline_bytes' => 2048,
        ],
    ],

    'notifications' => [
        'channels' => [],
        'on_success' => false,
        'on_failure' => true,
    ],

    'schedule' => [
        'backup' => '0 2 * * *',
        'prune' => '0 3 * * 0',
        'sweep' => '*/5 * * * *',
        'prune_keep_backup_drill_days' => 365,
    ],

    'table_prefix' => 'db_ops_',

    'log_channel' => 'stack',

    'operations_enabled' => true,

    'drivers' => [

        'shell' => [
            'commands' => [
                'logical_backup' => '',
                'logical_restore_latest' => '',
                'logical_restore_file' => '',
                'pitr_restore' => '',
            ],
            'health_binaries' => [],
        ],

        'mysql' => [
            'dump_binary' => env('DB_OPS_MYSQL_DUMP_BINARY', 'mysqldump'),
            'mysql_binary' => env('DB_OPS_MYSQL_BINARY', 'mysql'),
            'mysqlbinlog_binary' => env('DB_OPS_MYSQL_BINLOG_BINARY', 'mysqlbinlog'),
            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'drill' => [],
            ],
            'command_timeout_seconds' => 7200,
            'drill_command' => '',
            'health_binaries' => [
                ['code' => 'mysqldump', 'label' => 'mysqldump', 'binary' => 'mysqldump'],
                ['code' => 'mysql', 'label' => 'mysql', 'binary' => 'mysql'],
            ],
            'pitr' => [
                'binlog_files' => [],
            ],
        ],

        'postgres' => [

            'dump_binary' => env('DB_OPS_PG_DUMP_BINARY', 'pg_dump'),

            'restore_binary' => env('DB_OPS_PG_RESTORE_BINARY', 'pg_restore'),

            'format' => env('DB_OPS_PG_FORMAT', 'directory'),

            'jobs' => (int) env('DB_OPS_PG_JOBS', 4),

            'compress_level' => (int) env('DB_OPS_PG_COMPRESS_LEVEL', 6),

            'output_dir' => env('DB_OPS_PG_OUTPUT_DIR', ''),

            'output_prefix' => env('DB_OPS_PG_OUTPUT_PREFIX', 'logical-export'),

            'file_extension' => env('DB_OPS_PG_FILE_EXTENSION', 'dump'),

            'clean' => (bool) env('DB_OPS_PG_CLEAN', true),

            'create' => (bool) env('DB_OPS_PG_CREATE', false),

            'drill_command' => env('DB_OPS_PG_DRILL_COMMAND', ''),

            'command_timeout_seconds' => (int) env('DB_OPS_PG_COMMAND_TIMEOUT', 7200),

            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'drill' => [],
                'replication' => [],
            ],

            'binary' => env('DB_OPS_PG_BINARY', 'pg_basebackup'),

            'physical_output_dir' => env('DB_OPS_PG_PHYSICAL_OUTPUT_DIR', ''),

            'physical_extra_args' => [],

            'physical_wal_method' => env('DB_OPS_PG_PHYSICAL_WAL_METHOD', 'stream'),

            'physical_compression' => env('DB_OPS_PG_PHYSICAL_COMPRESSION', 'gzip'),

            'physical_checkpoint' => env('DB_OPS_PG_PHYSICAL_CHECKPOINT', 'fast'),

            'physical_max_rate' => env('DB_OPS_PG_PHYSICAL_MAX_RATE'),

            'host' => env('DB_OPS_PG_HOST'),

            'port' => env('DB_OPS_PG_PORT'),

            'username' => env('DB_OPS_PG_USERNAME'),

            'health_binaries' => ['pg_dump', 'pg_restore', 'pg_basebackup'],
        ],

    ],

    'temp_dir' => storage_path('app/checkpoint/tmp'),

];
