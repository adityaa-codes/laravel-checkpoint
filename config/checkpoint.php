<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBaseBackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;

$timeout = (int) env('CP_QUEUE_TIMEOUT', 3600);
$env = (string) (getenv('APP_ENV') ?: 'production');
$isLocal = in_array($env, ['local', 'testing'], true);

return [
    'operations_enabled' => env('CP_OPERATIONS_ENABLED', true),

    'driver' => env('CP_DRIVER') ?: match (strtolower(trim((string) config('database.connections.'.config('database.default', 'mysql').'.driver', 'mysql')))) {
        'pgsql', 'postgres', 'postgresql' => 'postgres',
        'mysql', 'mariadb' => 'mysql',
        'sqlite' => 'shell',
        default => 'shell',
    },

    'table_prefix' => env('CP_TABLE_PREFIX', 'db_ops_'),
    'temp_dir' => env('CP_TEMP_DIR', storage_path('app/checkpoint/tmp')),
    'log_channel' => env('CP_LOG_CHANNEL', 'stack'),

    'queue' => [
        'connection' => env('CP_QUEUE_CONNECTION', config('queue.default')),
        'name' => env('CP_QUEUE_NAME', 'db-ops'),
        'max_attempts' => 1,
        'retry_after' => $timeout + 60,
        'timeout' => $timeout,
        'unique_for' => $timeout + 60,
        'lock_store' => env('CP_QUEUE_LOCK_STORE'),
        'orphan_threshold' => 10,
        'orphan_claim_timeout' => (int) ceil(($timeout + 60) / 60),
        'orphan_batch_size' => 100,
        'orphan_event_max_ids' => 50,
        'heartbeat_interval_seconds' => 30,
        'heartbeat_grace_seconds' => 60,
    ],

    'restore' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
        ), static fn (string $v): bool => $v !== '')),
        'allowed_databases' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_DATABASES', '')),
        ), static fn (string $v): bool => $v !== '')),
        'require_confirmation' => ! $isLocal,
        'confirmation_phrase' => 'RESTORE',
        'allow_in_ci' => $isLocal,
        'require_verified_backup' => ! $isLocal,
        'ci' => (bool) env('CI', false),
        'blast_radius' => [
            'enabled' => true,
            'warn_score' => 50,
            'block_score' => 60,
            'weights' => [
                'environment' => 40,
                'database' => 25,
                'target' => 20,
                'verification' => 25,
            ],
        ],
    ],

    'replication' => [
        'require_confirmation_token' => true,
        'block_in_ci' => true,
        'require_dry_run_before_apply' => true,
        'enforce_change_window' => false,
        'change_window_timezone' => 'UTC',
        'change_window_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        'change_window_start' => '00:00',
        'change_window_end' => '23:59',
        'allowlisted_destinations' => [],
        'critical_tables' => [],
        'profiles' => [],
    ],

    'schedule' => [
        'logical_backup_enabled' => true,
        'logical_backup_daily_at' => env('CP_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => config('app.timezone', 'UTC'),
        'backup_drill_enabled' => false,
        'backup_drill_daily_at' => '03:00',
        'backup_drill_timezone' => config('app.timezone', 'UTC'),
        'health_check_enabled' => true,
        'recover_orphans_enabled' => true,
        'prune_enabled' => true,
        'without_overlapping' => true,
        'overlap_expires_at' => 180,
        'on_one_server' => true,
        'prune_keep_days' => 90,
        'prune_keep_failed_days' => 365,
        'prune_keep_backup_drill_days' => 365,
    ],

    'observability' => [
        'max_last_known_good_age_hours' => 24,
        'backup_duration_anomaly_factor' => 2.0,
        'backup_duration_min_samples' => 3,
        'max_backup_drill_age_days' => 30,
        'backup_drill_pass_rate_window_days' => 30,
        'backup_drill_min_pass_rate' => 100.0,
        'alert_cooldown_seconds' => 300,
    ],

    'retention' => [
        'enabled' => true,
        'default_days' => 90,
        'failed_days' => 365,
        'tiers' => [
            'hot' => 14,
            'warm' => 60,
            'cold' => 180,
        ],
    ],

    'reporting' => [
        'max_recent_runs' => 100,
    ],

    'notifications' => [
        'enabled' => env('CP_NOTIFICATIONS_ENABLED', false),
        'events' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_NOTIFICATIONS_EVENTS', '')),
        ), static fn (string $v): bool => $v !== '')),
        'routing' => [
            'info' => ['log'],
            'warning' => ['log', 'mail'],
            'critical' => ['log', 'mail', 'webhook'],
        ],
        'mail' => [
            'to' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CP_NOTIFICATIONS_MAIL_TO', '')),
            ), static fn (string $v): bool => $v !== '')),
        ],
        'webhook' => [
            'url' => env('CP_NOTIFICATIONS_WEBHOOK_URL'),
            'provider' => 'generic',
            'timeout_seconds' => 5,
        ],
    ],

    'output' => [
        'max_persisted_bytes' => 65536,
        'storage' => 'database',
        'filesystem' => [
            'disk' => 'local',
            'path_prefix' => 'checkpoint/command-output',
            'inline_bytes' => 2048,
        ],
    ],

    'gates' => [
        'override_profile' => env('CP_GATE_PROFILE'),
        'default_profile' => 'production',
        'environment_profile_map' => [
            'local' => 'local',
            'testing' => 'local',
            'staging' => 'staging',
            'production' => 'production',
        ],
        'code_map' => [
            'pass' => 0,
            'warn' => 2,
            'safety_fail' => 10,
            'evidence_fail' => 11,
            'policy_error' => 12,
        ],
        'profiles' => [
            'local' => [
                'exit_on_warn' => false,
                'safety' => ['fail_on_statuses' => ['fail'], 'fail_on_warning_codes' => []],
                'evidence' => [
                    'enabled' => false,
                    'fail_on_codes' => ['restore.post_verification', 'backup_drill.latest_run', 'backup_drill.pass_rate', 'verification.runs'],
                    'max_restore_verification_age_days' => 0,
                ],
            ],
            'staging' => [
                'exit_on_warn' => false,
                'safety' => ['fail_on_statuses' => ['fail'], 'fail_on_warning_codes' => ['restore.posture.environments', 'restore.posture.databases', 'restore.posture.ci_bypass', 'restore.posture.verified_backup', 'queue.orphaned_runs', 'config.validation']],
                'evidence' => [
                    'enabled' => true,
                    'fail_on_codes' => ['restore.post_verification', 'backup_drill.latest_run', 'backup_drill.pass_rate', 'verification.runs'],
                    'max_restore_verification_age_days' => 14,
                ],
            ],
            'production' => [
                'exit_on_warn' => false,
                'safety' => ['fail_on_statuses' => ['fail'], 'fail_on_warning_codes' => ['restore.posture.environments', 'restore.posture.databases', 'restore.posture.ci_bypass', 'restore.posture.verified_backup', 'queue.orphaned_runs', 'config.validation']],
                'evidence' => [
                    'enabled' => true,
                    'fail_on_codes' => ['restore.post_verification', 'backup_drill.latest_run', 'backup_drill.pass_rate', 'verification.runs'],
                    'max_restore_verification_age_days' => 7,
                ],
            ],
        ],
    ],

    'drivers' => [
        'shell' => [
            'class' => ShellCommandDriver::class,
            'commands' => [
                'logical_backup' => env('CP_CMD_LOGICAL_BACKUP', ''),
                'logical_restore_latest' => env('CP_CMD_RESTORE_LATEST', ''),
                'logical_restore_file' => env('CP_CMD_RESTORE_FILE', ''),
                'pitr_restore' => env('CP_CMD_PITR_RESTORE', ''),
                'backup_drill' => env('CP_CMD_BACKUP_DRILL', ''),
            ],
            'backup_dir' => env('CP_BACKUP_DIR', storage_path('db-backups')),
            'backup_prefix' => 'backup',
            'pre_restore_snapshot' => true,
            'command_timeout_seconds' => $timeout,
        ],
        'postgres' => [
            'class' => PostgresDriver::class,
        ],
        'pgbasebackup' => [
            'class' => PgBaseBackupDriver::class,
            'binary' => env('CP_PGBASEBACKUP_BINARY', 'pg_basebackup'),
            'output_dir' => storage_path('app/checkpoint/basebackups'),
            'timeout' => $timeout,
        ],
        'pgdump' => [
            'class' => PgDumpDriver::class,
            'dump_binary' => env('CP_PGDUMP_BINARY', 'pg_dump'),
            'restore_binary' => env('CP_PGRESTORE_BINARY', 'pg_restore'),
            'format' => 'directory',
            'jobs' => 4,
            'compress_level' => 6,
            'output_dir' => storage_path('app/checkpoint/logical-exports'),
            'output_prefix' => 'logical-export',
            'file_extension' => 'dump',
            'clean' => true,
            'create' => false,
            'drill_command' => '',
            'command_timeout_seconds' => $timeout,
            'extra_args' => ['backup' => [], 'restore' => []],
        ],
        'mysql' => [
            'class' => MysqlDriver::class,
            'dump_binary' => env('CP_MYSQL_DUMP_BINARY', 'mysqldump'),
            'mysql_binary' => env('CP_MYSQL_BINARY', 'mysql'),
            'mysqlbinlog_binary' => env('CP_MYSQL_BINLOG_BINARY', 'mysqlbinlog'),
            'single_transaction' => true,
            'quick' => true,
            'skip_lock_tables' => true,
            'output_dir' => storage_path('app/checkpoint/mysql/logical-exports'),
            'output_prefix' => 'mysql-export',
            'file_extension' => 'sql',
            'drill_command' => '',
            'command_timeout_seconds' => $timeout,
            'pitr' => [
                'binlog_files' => array_values(array_filter(array_map(
                    trim(...),
                    explode(',', (string) env('CP_MYSQL_PITR_BINLOG_FILES', '')),
                ), static fn (string $v): bool => $v !== '')),
            ],
            'extra_args' => ['backup' => [], 'restore' => [], 'pitr_binlog' => [], 'pitr_replay' => [], 'drill' => []],
        ],
    ],

    'custom_operations' => [],
];
