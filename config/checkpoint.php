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
    'driver' => env('CP_DRIVER') ?: match (strtolower(trim((string) config('database.connections.'.config('database.default', 'mysql').'.driver', 'mysql')))) {
        'pgsql', 'postgres', 'postgresql' => 'postgres',
        'mysql', 'mariadb' => 'mysql',
        'sqlite' => 'shell',
        default => 'shell',
    },

    'queue' => [
        'connection' => config('queue.default'),
        'name' => env('CP_QUEUE_NAME', 'db-ops'),
        'timeout' => $timeout,
        'retry_after' => $timeout + 60,
        'unique_for' => $timeout + 60,
        'lock_store' => config('cache.default'),
        'max_attempts' => 1,
        'orphan_threshold' => 10,
        'orphan_claim_timeout' => (int) ceil(($timeout + 60) / 60),
        'orphan_batch_size' => 100,
        'orphan_event_max_ids' => 50,
        'heartbeat_interval_seconds' => 30,
        'heartbeat_grace_seconds' => 60,
    ],

    'log_channel' => config('logging.default', 'stack'),
    'table_prefix' => 'db_ops_',
    'temp_dir' => storage_path('app/checkpoint/tmp'),

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

    'schedule' => [
        'logical_backup_enabled' => true,
        'logical_backup_daily_at' => '16:00',
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
        'enabled' => env('CP_NOTIFICATIONS_WEBHOOK_URL') !== null,
        'events' => [],
        'routing' => [
            'info' => ['log'],
            'warning' => ['log', 'mail'],
            'critical' => ['log', 'mail', 'webhook'],
        ],
        'mail' => [
            'to' => [],
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
        'override_profile' => null,
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
                'logical_backup' => '',
                'logical_restore_latest' => '',
                'logical_restore_file' => '',
                'pitr_restore' => '',
                'backup_drill' => '',
            ],
            'backup_dir' => storage_path('db-backups'),
            'backup_prefix' => 'backup',
            'pre_restore_snapshot' => true,
            'command_timeout_seconds' => $timeout,
        ],
        'postgres' => [
            'class' => PostgresDriver::class,
        ],
        'pgbasebackup' => [
            'class' => PgBaseBackupDriver::class,
            'binary' => 'pg_basebackup',
            'output_dir' => storage_path('app/checkpoint/basebackups'),
            'timeout' => $timeout,
        ],
        'pgdump' => [
            'class' => PgDumpDriver::class,
            'dump_binary' => 'pg_dump',
            'restore_binary' => 'pg_restore',
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
            'dump_binary' => 'mysqldump',
            'mysql_binary' => 'mysql',
            'mysqlbinlog_binary' => 'mysqlbinlog',
            'single_transaction' => true,
            'quick' => true,
            'skip_lock_tables' => true,
            'output_dir' => storage_path('app/checkpoint/mysql/logical-exports'),
            'output_prefix' => 'mysql-export',
            'file_extension' => 'sql',
            'drill_command' => '',
            'command_timeout_seconds' => $timeout,
            'pitr' => [
                'binlog_files' => [],
            ],
            'extra_args' => ['backup' => [], 'restore' => [], 'pitr_binlog' => [], 'pitr_replay' => [], 'drill' => []],
        ],
    ],

    'custom_operations' => [],
];
