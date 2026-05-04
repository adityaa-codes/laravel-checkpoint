<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBaseBackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use Illuminate\Foundation\Auth\User;

/*
|--------------------------------------------------------------------------
| Minimal Setup (5 env vars for basic operation)
|--------------------------------------------------------------------------
| CP_DRIVER           → Driver selection (auto-detected if omitted)
| CP_QUEUE_CONNECTION → Queue connection for async job dispatch
| CP_RESTORE_ALLOWED_ENVIRONMENTS → Environments where restore may run
| CP_RESTORE_REQUIRE_CONFIRMATION → Require interactive restore confirmation
| CP_NOTIFICATIONS_ENABLED         → Turn on failure/success notifications
|
| All other keys have safe defaults. Tune blast-radius, retention, and
| observability thresholds in production before your first restore drill.
*/
$appEnv = (string) (getenv('APP_ENV') ?: env('APP_ENV', 'production'));
$nonLocalPosture = ! in_array($appEnv, ['local', 'testing'], true);
$timeoutBase = (int) env('CP_TIMEOUT', env('CP_QUEUE_TIMEOUT', 3600));

return [
    'operations_enabled' => env('CP_OPERATIONS_ENABLED', true),

    'user_model' => env('CP_USER_MODEL', User::class),
    'user_name_column' => env('CP_USER_NAME_COLUMN', 'name'),
    'table_prefix' => env('CP_TABLE_PREFIX', 'db_ops_'),

    'queue' => [
        'connection' => env('CP_QUEUE_CONNECTION'),
        'name' => env('CP_QUEUE_NAME', 'db-ops'),
        'max_attempts' => (int) env('CP_QUEUE_MAX_ATTEMPTS', 1),
        'retry_after' => (int) env('CP_QUEUE_RETRY_AFTER', $timeoutBase + 60),
        'timeout' => (int) env('CP_QUEUE_TIMEOUT', $timeoutBase),
        'orphan_threshold' => (int) env('CP_QUEUE_ORPHAN_THRESHOLD', 10),
        'orphan_claim_timeout' => (int) env('CP_QUEUE_ORPHAN_CLAIM_TIMEOUT', (int) ceil(((int) env('CP_QUEUE_RETRY_AFTER', (string) ($timeoutBase + 60))) / 60)),
        'orphan_batch_size' => (int) env('CP_QUEUE_ORPHAN_BATCH_SIZE', 100),
        'orphan_event_max_ids' => (int) env('CP_QUEUE_ORPHAN_EVENT_MAX_IDS', 50),
        'heartbeat_interval_seconds' => (int) env('CP_QUEUE_HEARTBEAT_INTERVAL_SECONDS', 30),
        'heartbeat_grace_seconds' => (int) env('CP_QUEUE_HEARTBEAT_GRACE_SECONDS', 60),
        'unique_for' => (int) env('CP_QUEUE_UNIQUE_FOR', $timeoutBase + 60),
        'lock_store' => env('CP_QUEUE_LOCK_STORE'),
    ],

    'restore' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
        ), static fn (string $value): bool => $value !== '')),
        'allowed_databases' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_DATABASES', '')),
        ), static fn (string $value): bool => $value !== '')),
        'require_confirmation' => (bool) env('CP_RESTORE_REQUIRE_CONFIRMATION', true),
        'confirmation_phrase' => env('CP_RESTORE_CONFIRMATION_PHRASE', 'RESTORE'),
        'confirmation_token' => env('CP_RESTORE_CONFIRMATION'),
        'allow_in_ci' => (bool) env('CP_RESTORE_ALLOW_IN_CI', false),
        'ci' => (bool) env('CI', false),
        'require_verified_backup' => (bool) env('CP_RESTORE_REQUIRE_VERIFIED_BACKUP', $nonLocalPosture),
        'blast_radius' => [
            'enabled' => (bool) env('CP_RESTORE_BLAST_RADIUS_ENABLED', true),
            'warn_score' => (int) env('CP_RESTORE_BLAST_RADIUS_WARN_SCORE', 50),
            'block_score' => (int) env('CP_RESTORE_BLAST_RADIUS_BLOCK_SCORE', 60),
            'weights' => [
                'environment' => (int) env('CP_RESTORE_BLAST_RADIUS_WEIGHT_ENVIRONMENT', 40),
                'database' => (int) env('CP_RESTORE_BLAST_RADIUS_WEIGHT_DATABASE', 25),
                'target' => (int) env('CP_RESTORE_BLAST_RADIUS_WEIGHT_TARGET', 20),
                'verification' => (int) env('CP_RESTORE_BLAST_RADIUS_WEIGHT_VERIFICATION', 25),
            ],
        ],
    ],

    'replication' => [
        'require_confirmation_token' => (bool) env('CP_REPLICATION_REQUIRE_CONFIRMATION_TOKEN', true),
        'block_in_ci' => (bool) env('CP_REPLICATION_BLOCK_IN_CI', true),
        'require_dry_run_before_apply' => (bool) env('CP_REPLICATION_REQUIRE_DRY_RUN_BEFORE_APPLY', true),
        'enforce_change_window' => (bool) env('CP_REPLICATION_ENFORCE_CHANGE_WINDOW', false),
        'change_window_timezone' => (string) env('CP_REPLICATION_CHANGE_WINDOW_TIMEZONE', 'UTC'),
        'change_window_days' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_REPLICATION_CHANGE_WINDOW_DAYS', 'mon,tue,wed,thu,fri,sat,sun')),
        ), static fn (string $value): bool => $value !== '')),
        'change_window_start' => (string) env('CP_REPLICATION_CHANGE_WINDOW_START', '00:00'),
        'change_window_end' => (string) env('CP_REPLICATION_CHANGE_WINDOW_END', '23:59'),
        'allowlisted_destinations' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_REPLICATION_ALLOWLISTED_DESTINATIONS', '')),
        ), static fn (string $value): bool => $value !== '')),
        'critical_tables' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_REPLICATION_CRITICAL_TABLES', '')),
        ), static fn (string $value): bool => $value !== '')),
        'profiles' => [],
    ],

    'schedule' => [
        'logical_backup_enabled' => (bool) env('CP_BACKUP_SCHEDULE_ENABLED', true),
        'logical_backup_daily_at' => env('CP_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => env('CP_BACKUP_TIMEZONE', 'UTC'),
        'backup_drill_enabled' => (bool) env('CP_BACKUP_DRILL_SCHEDULE_ENABLED', false),
        'backup_drill_daily_at' => env('CP_BACKUP_DRILL_DAILY_AT', '03:00'),
        'backup_drill_timezone' => env('CP_BACKUP_DRILL_TIMEZONE', 'UTC'),
        'health_check_enabled' => (bool) env('CP_HEALTH_CHECK_ENABLED', true),
        'recover_orphans_enabled' => (bool) env('CP_RECOVER_ORPHANS_ENABLED', true),
        'prune_enabled' => (bool) env('CP_PRUNE_ENABLED', true),
        'without_overlapping' => (bool) env('CP_SCHEDULE_WITHOUT_OVERLAPPING', true),
        'overlap_expires_at' => (int) env('CP_SCHEDULE_OVERLAP_EXPIRES_AT', 180),
        'on_one_server' => (bool) env('CP_SCHEDULE_ON_ONE_SERVER', true),
        'prune_keep_days' => (int) env('CP_PRUNE_KEEP_DAYS', 90),
        'prune_keep_failed_days' => (int) env('CP_PRUNE_KEEP_FAILED_DAYS', 365),
        'prune_keep_backup_drill_days' => (int) env('CP_PRUNE_KEEP_BACKUP_DRILL_DAYS', 365),
    ],

    'driver' => env('CP_DRIVER') ?: match (strtolower(trim((string) config('database.connections.'.config('database.default', 'mysql').'.driver', 'mysql')))) {
        'pgsql', 'postgres', 'postgresql' => 'postgres',
        'mysql', 'mariadb' => 'mysql',
        'sqlite' => 'shell',
        default => 'shell',
    },
    'observability' => [
        'max_last_known_good_age_hours' => (int) env('CP_MAX_LAST_KNOWN_GOOD_AGE_HOURS', 24),
        'backup_duration_anomaly_factor' => (float) env('CP_BACKUP_DURATION_ANOMALY_FACTOR', 2.0),
        'backup_duration_min_samples' => (int) env('CP_BACKUP_DURATION_MIN_SAMPLES', 3),
        'max_backup_drill_age_days' => (int) env('CP_MAX_BACKUP_DRILL_AGE_DAYS', 30),
        'backup_drill_pass_rate_window_days' => (int) env('CP_BACKUP_DRILL_PASS_RATE_WINDOW_DAYS', 30),
        'backup_drill_min_pass_rate' => (float) env('CP_BACKUP_DRILL_MIN_PASS_RATE', 100.0),
        'alert_cooldown_seconds' => (int) env('CP_ALERT_COOLDOWN_SECONDS', 300),
    ],
    'reporting' => [
        'max_recent_runs' => (int) env('CP_REPORTING_MAX_RECENT_RUNS', 100),
    ],
    'retention' => [
        'enabled' => (bool) env('CP_RETENTION_ENABLED', true),
        'default_days' => (int) env('CP_RETENTION_DEFAULT_DAYS', (int) env('CP_PRUNE_KEEP_DAYS', 90)),
        'failed_days' => (int) env('CP_RETENTION_FAILED_DAYS', (int) env('CP_PRUNE_KEEP_FAILED_DAYS', 365)),
        'tiers' => [
            'hot' => (int) env('CP_RETENTION_TIER_HOT_DAYS', 14),
            'warm' => (int) env('CP_RETENTION_TIER_WARM_DAYS', 60),
            'cold' => (int) env('CP_RETENTION_TIER_COLD_DAYS', 180),
        ],
    ],
    'notifications' => [
        'enabled' => (bool) env('CP_NOTIFICATIONS_ENABLED', false),
        'events' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_NOTIFICATIONS_EVENTS', '')),
        ), static fn (string $value): bool => $value !== '')),
        'routing' => [
            'info' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CP_NOTIFICATIONS_ROUTE_INFO', 'log')),
            ), static fn (string $value): bool => $value !== '')),
            'warning' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CP_NOTIFICATIONS_ROUTE_WARNING', 'log,mail')),
            ), static fn (string $value): bool => $value !== '')),
            'critical' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CP_NOTIFICATIONS_ROUTE_CRITICAL', 'log,mail,webhook')),
            ), static fn (string $value): bool => $value !== '')),
        ],
        'mail' => [
            'to' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('CP_NOTIFICATIONS_MAIL_TO', '')),
            ), static fn (string $value): bool => $value !== '')),
        ],
        'webhook' => [
            'url' => env('CP_NOTIFICATIONS_WEBHOOK_URL'),
            'provider' => env('CP_NOTIFICATIONS_WEBHOOK_PROVIDER', 'generic'),
            'timeout_seconds' => (int) env('CP_NOTIFICATIONS_WEBHOOK_TIMEOUT_SECONDS', 5),
        ],
    ],
    'output' => [
        'max_persisted_bytes' => (int) env('CP_OUTPUT_MAX_PERSISTED_BYTES', 65536),
        'storage' => env('CP_OUTPUT_STORAGE', 'database'),
        'filesystem' => [
            'disk' => env('CP_OUTPUT_FILESYSTEM_DISK', 'local'),
            'path_prefix' => env('CP_OUTPUT_FILESYSTEM_PATH_PREFIX', 'checkpoint/command-output'),
            'inline_bytes' => (int) env('CP_OUTPUT_FILESYSTEM_INLINE_BYTES', 2048),
        ],
    ],
    'gates' => [
        'override_profile' => env('CP_GATE_PROFILE'),
        'default_profile' => (string) env('CP_GATE_DEFAULT_PROFILE', 'production'),
        'environment_profile_map' => [
            'local' => (string) env('CP_GATE_PROFILE_LOCAL', 'local'),
            'testing' => (string) env('CP_GATE_PROFILE_TESTING', 'local'),
            'staging' => (string) env('CP_GATE_PROFILE_STAGING', 'staging'),
            'production' => (string) env('CP_GATE_PROFILE_PRODUCTION', 'production'),
        ],
        'code_map' => [
            'pass' => (int) env('CP_GATE_EXIT_PASS', 0),
            'warn' => (int) env('CP_GATE_EXIT_WARN', 2),
            'safety_fail' => (int) env('CP_GATE_EXIT_SAFETY_FAIL', 10),
            'evidence_fail' => (int) env('CP_GATE_EXIT_EVIDENCE_FAIL', 11),
            'policy_error' => (int) env('CP_GATE_EXIT_POLICY_ERROR', 12),
        ],
        'profiles' => [
            'local' => [
                'exit_on_warn' => (bool) env('CP_GATE_LOCAL_EXIT_ON_WARN', false),
                'safety' => [
                    'fail_on_statuses' => ['fail'],
                    'fail_on_warning_codes' => [],
                ],
                'evidence' => [
                    'enabled' => (bool) env('CP_GATE_LOCAL_EVIDENCE_ENABLED', false),
                    'fail_on_codes' => [
                        'restore.post_verification',
                        'backup_drill.latest_run',
                        'backup_drill.pass_rate',
                        'verification.runs',
                    ],
                    'max_restore_verification_age_days' => (int) env('CP_GATE_LOCAL_MAX_RESTORE_VERIFICATION_AGE_DAYS', 0),
                ],
            ],
            'staging' => [
                'exit_on_warn' => (bool) env('CP_GATE_STAGING_EXIT_ON_WARN', false),
                'safety' => [
                    'fail_on_statuses' => ['fail'],
                    'fail_on_warning_codes' => [
                        'restore.posture.environments',
                        'restore.posture.databases',
                        'restore.posture.ci_bypass',
                        'restore.posture.verified_backup',
                        'queue.orphaned_runs',
                        'config.validation',
                    ],
                ],
                'evidence' => [
                    'enabled' => (bool) env('CP_GATE_STAGING_EVIDENCE_ENABLED', true),
                    'fail_on_codes' => [
                        'restore.post_verification',
                        'backup_drill.latest_run',
                        'backup_drill.pass_rate',
                        'verification.runs',
                    ],
                    'max_restore_verification_age_days' => (int) env('CP_GATE_STAGING_MAX_RESTORE_VERIFICATION_AGE_DAYS', 14),
                ],
            ],
            'production' => [
                'exit_on_warn' => (bool) env('CP_GATE_PRODUCTION_EXIT_ON_WARN', false),
                'safety' => [
                    'fail_on_statuses' => ['fail'],
                    'fail_on_warning_codes' => [
                        'restore.posture.environments',
                        'restore.posture.databases',
                        'restore.posture.ci_bypass',
                        'restore.posture.verified_backup',
                        'queue.orphaned_runs',
                        'config.validation',
                    ],
                ],
                'evidence' => [
                    'enabled' => (bool) env('CP_GATE_PRODUCTION_EVIDENCE_ENABLED', true),
                    'fail_on_codes' => [
                        'restore.post_verification',
                        'backup_drill.latest_run',
                        'backup_drill.pass_rate',
                        'verification.runs',
                    ],
                    'max_restore_verification_age_days' => (int) env('CP_GATE_PRODUCTION_MAX_RESTORE_VERIFICATION_AGE_DAYS', 7),
                ],
            ],
        ],
    ],
    'temp_dir' => env('CP_TEMP_DIR', storage_path('app/checkpoint/tmp')),
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
            'backup_prefix' => env('CP_BACKUP_PREFIX', 'backup'),
            'pre_restore_snapshot' => (bool) env('CP_PRE_RESTORE_SNAPSHOT', true),
            'command_timeout_seconds' => (int) env('CP_CMD_TIMEOUT', $timeoutBase),
        ],
        'postgres' => [
            'class' => PostgresDriver::class,
        ],
        'pgbasebackup' => [
            'class' => PgBaseBackupDriver::class,
            'binary' => env('CP_PGBASEBACKUP_BINARY', 'pg_basebackup'),
            'output_dir' => env('CP_PGBASEBACKUP_DIR', storage_path('app/checkpoint/basebackups')),
            'timeout' => (int) env('CP_PGBASEBACKUP_TIMEOUT', $timeoutBase),
        ],
        'pgdump' => [
            'class' => PgDumpDriver::class,
            'dump_binary' => env('CP_PGDUMP_BINARY', 'pg_dump'),
            'restore_binary' => env('CP_PGRESTORE_BINARY', 'pg_restore'),
            'format' => env('CP_PGDUMP_FORMAT', 'directory'),
            'jobs' => (int) env('CP_PGDUMP_JOBS', 4),
            'compress_level' => (int) env('CP_PGDUMP_COMPRESS_LEVEL', 6),
            'output_dir' => env('CP_PGDUMP_OUTPUT_DIR', storage_path('app/checkpoint/logical-exports')),
            'output_prefix' => env('CP_PGDUMP_OUTPUT_PREFIX', 'logical-export'),
            'file_extension' => env('CP_PGDUMP_FILE_EXTENSION', 'dump'),
            'clean' => (bool) env('CP_PGDUMP_RESTORE_CLEAN', true),
            'create' => (bool) env('CP_PGDUMP_RESTORE_CREATE', false),
            'drill_command' => env('CP_PGDUMP_DRILL_COMMAND', ''),
            'command_timeout_seconds' => (int) env('CP_PGDUMP_TIMEOUT', $timeoutBase),
            'extra_args' => [
                'backup' => [],
                'restore' => [],
            ],
        ],
        'mysql' => [
            'class' => MysqlDriver::class,
            'dump_binary' => env('CP_MYSQL_DUMP_BINARY', 'mysqldump'),
            'mysql_binary' => env('CP_MYSQL_BINARY', 'mysql'),
            'mysqlbinlog_binary' => env('CP_MYSQL_BINLOG_BINARY', 'mysqlbinlog'),
            'single_transaction' => (bool) env('CP_MYSQL_SINGLE_TRANSACTION', true),
            'quick' => (bool) env('CP_MYSQL_QUICK', true),
            'skip_lock_tables' => (bool) env('CP_MYSQL_SKIP_LOCK_TABLES', true),
            'output_dir' => env('CP_MYSQL_OUTPUT_DIR', storage_path('app/checkpoint/mysql/logical-exports')),
            'output_prefix' => env('CP_MYSQL_OUTPUT_PREFIX', 'mysql-export'),
            'file_extension' => env('CP_MYSQL_FILE_EXTENSION', 'sql'),
            'drill_command' => env('CP_MYSQL_DRILL_COMMAND', ''),
            'command_timeout_seconds' => (int) env('CP_MYSQL_TIMEOUT', $timeoutBase),
            'pitr' => [
                'binlog_files' => array_values(array_filter(array_map(
                    trim(...),
                    explode(',', (string) env('CP_MYSQL_PITR_BINLOG_FILES', '')),
                ), static fn (string $value): bool => $value !== '')),
            ],
            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'pitr_binlog' => [],
                'pitr_replay' => [],
                'drill' => [],
            ],
        ],
    ],

    'log_channel' => env('CP_LOG_CHANNEL', 'stack'),
    'custom_operations' => [],
];
