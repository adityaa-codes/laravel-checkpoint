<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use Illuminate\Foundation\Auth\User;

/** @phpstan-ignore-next-line */
$env = static fn (string $key, mixed $default = null): mixed => env($key, $default);
$appEnv = (string) (getenv('APP_ENV') ?: $env('APP_ENV', 'production'));
$nonLocalPosture = ! in_array($appEnv, ['local', 'testing'], true);
$queueTimeoutDefault = (int) $env('DB_OPS_QUEUE_TIMEOUT', 3600);

return [
    'user_model' => $env('DB_OPS_USER_MODEL', User::class),
    'user_name_column' => $env('DB_OPS_USER_NAME_COLUMN', 'name'),
    'table_prefix' => $env('DB_OPS_TABLE_PREFIX', 'db_ops_'),

    'queue' => [
        'connection' => $env('DB_OPS_QUEUE_CONNECTION'),
        'name' => $env('DB_OPS_QUEUE_NAME', 'db-ops'),
        'max_attempts' => (int) $env('DB_OPS_QUEUE_MAX_ATTEMPTS', 1),
        'retry_after' => (int) $env('DB_OPS_QUEUE_RETRY_AFTER', 3660),
        'timeout' => $queueTimeoutDefault,
        'orphan_threshold' => (int) $env('DB_OPS_QUEUE_ORPHAN_THRESHOLD', 10),
        'orphan_claim_timeout' => (int) $env('DB_OPS_QUEUE_ORPHAN_CLAIM_TIMEOUT', (int) ceil(((int) $env('DB_OPS_QUEUE_RETRY_AFTER', 3660)) / 60)),
        'orphan_batch_size' => (int) $env('DB_OPS_QUEUE_ORPHAN_BATCH_SIZE', 100),
        'orphan_event_max_ids' => (int) $env('DB_OPS_QUEUE_ORPHAN_EVENT_MAX_IDS', 50),
        'heartbeat_interval_seconds' => (int) $env('DB_OPS_QUEUE_HEARTBEAT_INTERVAL_SECONDS', 30),
        'heartbeat_grace_seconds' => (int) $env('DB_OPS_QUEUE_HEARTBEAT_GRACE_SECONDS', 60),
        'unique_for' => (int) $env('DB_OPS_QUEUE_UNIQUE_FOR', 3660),
        'lock_store' => $env('DB_OPS_QUEUE_LOCK_STORE'),
    ],

    'restore' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
        ), static fn (string $value): bool => $value !== '')),
        'allowed_databases' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_RESTORE_ALLOWED_DATABASES', '')),
        ), static fn (string $value): bool => $value !== '')),
        'require_confirmation' => (bool) $env('DB_OPS_RESTORE_REQUIRE_CONFIRMATION', true),
        'confirmation_phrase' => $env('DB_OPS_RESTORE_CONFIRMATION_PHRASE', 'RESTORE'),
        'confirmation_token' => $env('DB_OPS_RESTORE_CONFIRMATION'),
        'allow_in_ci' => (bool) $env('DB_OPS_RESTORE_ALLOW_IN_CI', false),
        'ci' => (bool) $env('CI', false),
        'require_verified_backup' => (bool) $env('DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP', $nonLocalPosture),
        'blast_radius' => [
            'enabled' => (bool) $env('DB_OPS_RESTORE_BLAST_RADIUS_ENABLED', true),
            'warn_score' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_WARN_SCORE', 50),
            'block_score' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_BLOCK_SCORE', 80),
            'weights' => [
                'environment' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_WEIGHT_ENVIRONMENT', 30),
                'database' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_WEIGHT_DATABASE', 25),
                'target' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_WEIGHT_TARGET', 20),
                'verification' => (int) $env('DB_OPS_RESTORE_BLAST_RADIUS_WEIGHT_VERIFICATION', 25),
            ],
        ],
    ],

    'replication' => [
        'require_confirmation_token' => (bool) $env('DB_OPS_REPLICATION_REQUIRE_CONFIRMATION_TOKEN', true),
        'block_in_ci' => (bool) $env('DB_OPS_REPLICATION_BLOCK_IN_CI', true),
        'require_dry_run_before_apply' => (bool) $env('DB_OPS_REPLICATION_REQUIRE_DRY_RUN_BEFORE_APPLY', true),
        'enforce_change_window' => (bool) $env('DB_OPS_REPLICATION_ENFORCE_CHANGE_WINDOW', false),
        'change_window_timezone' => (string) $env('DB_OPS_REPLICATION_CHANGE_WINDOW_TIMEZONE', 'UTC'),
        'change_window_days' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_REPLICATION_CHANGE_WINDOW_DAYS', 'mon,tue,wed,thu,fri,sat,sun')),
        ), static fn (string $value): bool => $value !== '')),
        'change_window_start' => (string) $env('DB_OPS_REPLICATION_CHANGE_WINDOW_START', '00:00'),
        'change_window_end' => (string) $env('DB_OPS_REPLICATION_CHANGE_WINDOW_END', '23:59'),
        'allowlisted_destinations' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_REPLICATION_ALLOWLISTED_DESTINATIONS', '')),
        ), static fn (string $value): bool => $value !== '')),
        'critical_tables' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_REPLICATION_CRITICAL_TABLES', '')),
        ), static fn (string $value): bool => $value !== '')),
        'profiles' => [],
    ],

    'schedule' => [
        'logical_backup_enabled' => (bool) $env('DB_OPS_BACKUP_SCHEDULE_ENABLED', true),
        'logical_backup_daily_at' => $env('DB_OPS_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => $env('DB_OPS_BACKUP_TIMEZONE', 'UTC'),
        'backup_drill_enabled' => (bool) $env('DB_OPS_BACKUP_DRILL_SCHEDULE_ENABLED', false),
        'backup_drill_daily_at' => $env('DB_OPS_BACKUP_DRILL_DAILY_AT', '03:00'),
        'backup_drill_timezone' => $env('DB_OPS_BACKUP_DRILL_TIMEZONE', 'UTC'),
        'health_check_enabled' => (bool) $env('DB_OPS_HEALTH_CHECK_ENABLED', true),
        'recover_orphans_enabled' => (bool) $env('DB_OPS_RECOVER_ORPHANS_ENABLED', true),
        'prune_enabled' => (bool) $env('DB_OPS_PRUNE_ENABLED', true),
        'without_overlapping' => (bool) $env('DB_OPS_SCHEDULE_WITHOUT_OVERLAPPING', true),
        'overlap_expires_at' => (int) $env('DB_OPS_SCHEDULE_OVERLAP_EXPIRES_AT', 180),
        'on_one_server' => (bool) $env('DB_OPS_SCHEDULE_ON_ONE_SERVER', true),
        'prune_keep_days' => (int) $env('DB_OPS_PRUNE_KEEP_DAYS', 90),
        'prune_keep_failed_days' => (int) $env('DB_OPS_PRUNE_KEEP_FAILED_DAYS', 365),
        'prune_keep_backup_drill_days' => (int) $env('DB_OPS_PRUNE_KEEP_BACKUP_DRILL_DAYS', 365),
    ],

    'driver' => $env('DB_OPS_DRIVER', 'shell'),
    'observability' => [
        'max_last_known_good_age_hours' => (int) $env('DB_OPS_MAX_LAST_KNOWN_GOOD_AGE_HOURS', 24),
        'backup_duration_anomaly_factor' => (float) $env('DB_OPS_BACKUP_DURATION_ANOMALY_FACTOR', 2.0),
        'backup_duration_min_samples' => (int) $env('DB_OPS_BACKUP_DURATION_MIN_SAMPLES', 3),
        'max_backup_drill_age_days' => (int) $env('DB_OPS_MAX_BACKUP_DRILL_AGE_DAYS', 30),
        'backup_drill_pass_rate_window_days' => (int) $env('DB_OPS_BACKUP_DRILL_PASS_RATE_WINDOW_DAYS', 30),
        'backup_drill_min_pass_rate' => (float) $env('DB_OPS_BACKUP_DRILL_MIN_PASS_RATE', 100.0),
        'alert_cooldown_seconds' => (int) $env('DB_OPS_ALERT_COOLDOWN_SECONDS', 300),
    ],
    'reporting' => [
        'max_recent_runs' => (int) $env('DB_OPS_REPORTING_MAX_RECENT_RUNS', 100),
    ],
    'retention' => [
        'enabled' => (bool) $env('DB_OPS_RETENTION_ENABLED', true),
        'default_days' => (int) $env('DB_OPS_RETENTION_DEFAULT_DAYS', (int) $env('DB_OPS_PRUNE_KEEP_DAYS', 90)),
        'failed_days' => (int) $env('DB_OPS_RETENTION_FAILED_DAYS', (int) $env('DB_OPS_PRUNE_KEEP_FAILED_DAYS', 365)),
        'tiers' => [
            'hot' => (int) $env('DB_OPS_RETENTION_TIER_HOT_DAYS', 14),
            'warm' => (int) $env('DB_OPS_RETENTION_TIER_WARM_DAYS', 60),
            'cold' => (int) $env('DB_OPS_RETENTION_TIER_COLD_DAYS', 180),
        ],
    ],
    'notifications' => [
        'enabled' => (bool) $env('DB_OPS_NOTIFICATIONS_ENABLED', false),
        'events' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $env('DB_OPS_NOTIFICATIONS_EVENTS', '')),
        ), static fn (string $value): bool => $value !== '')),
        'routing' => [
            'info' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $env('DB_OPS_NOTIFICATIONS_ROUTE_INFO', 'log')),
            ), static fn (string $value): bool => $value !== '')),
            'warning' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $env('DB_OPS_NOTIFICATIONS_ROUTE_WARNING', 'log,mail')),
            ), static fn (string $value): bool => $value !== '')),
            'critical' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $env('DB_OPS_NOTIFICATIONS_ROUTE_CRITICAL', 'log,mail,webhook')),
            ), static fn (string $value): bool => $value !== '')),
        ],
        'mail' => [
            'to' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) $env('DB_OPS_NOTIFICATIONS_MAIL_TO', '')),
            ), static fn (string $value): bool => $value !== '')),
        ],
        'webhook' => [
            'url' => $env('DB_OPS_NOTIFICATIONS_WEBHOOK_URL'),
            'provider' => $env('DB_OPS_NOTIFICATIONS_WEBHOOK_PROVIDER', 'generic'),
            'timeout_seconds' => (int) $env('DB_OPS_NOTIFICATIONS_WEBHOOK_TIMEOUT_SECONDS', 5),
        ],
    ],
    'output' => [
        'max_persisted_bytes' => (int) $env('DB_OPS_OUTPUT_MAX_PERSISTED_BYTES', 65536),
        'storage' => $env('DB_OPS_OUTPUT_STORAGE', 'database'),
        'filesystem' => [
            'disk' => $env('DB_OPS_OUTPUT_FILESYSTEM_DISK', 'local'),
            'path_prefix' => $env('DB_OPS_OUTPUT_FILESYSTEM_PATH_PREFIX', 'checkpoint/command-output'),
            'inline_bytes' => (int) $env('DB_OPS_OUTPUT_FILESYSTEM_INLINE_BYTES', 2048),
        ],
    ],
    'temp_dir' => $env('DB_OPS_TEMP_DIR', storage_path('app/checkpoint/tmp')),
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
            'command_timeout_seconds' => (int) $env('DB_OPS_CMD_TIMEOUT', $queueTimeoutDefault),
        ],
        'pgbackrest' => [
            'class' => PgBackRestDriver::class,
            'binary' => $env('DB_OPS_PGBACKREST_BINARY', 'pgbackrest'),
            'stanza' => $env('DB_OPS_PGBACKREST_STANZA', 'main'),
            'repo' => (int) $env('DB_OPS_PGBACKREST_REPO', 1),
            'repositories' => [
                1 => [
                    'type' => $env('DB_OPS_PGBACKREST_REPO1_TYPE', 'posix'),
                    'path' => $env('DB_OPS_PGBACKREST_REPO1_PATH', storage_path('app/checkpoint/pgbackrest/repo1')),
                    's3' => [
                        'bucket' => $env('DB_OPS_PGBACKREST_REPO1_S3_BUCKET'),
                        'endpoint' => $env('DB_OPS_PGBACKREST_REPO1_S3_ENDPOINT'),
                        'region' => $env('DB_OPS_PGBACKREST_REPO1_S3_REGION'),
                        'key' => $env('DB_OPS_PGBACKREST_REPO1_S3_KEY'),
                        'secret' => $env('DB_OPS_PGBACKREST_REPO1_S3_SECRET'),
                        'uri_style' => $env('DB_OPS_PGBACKREST_REPO1_S3_URI_STYLE', 'host'),
                    ],
                    'tls' => [
                        'verify' => (bool) $env('DB_OPS_PGBACKREST_REPO1_TLS_VERIFY', true),
                        'ca_file' => $env('DB_OPS_PGBACKREST_REPO1_TLS_CA_FILE'),
                    ],
                    'encryption' => [
                        'enabled' => (bool) $env('DB_OPS_PGBACKREST_REPO1_ENCRYPTION_ENABLED', false),
                        'cipher_type' => $env('DB_OPS_PGBACKREST_REPO1_ENCRYPTION_CIPHER', 'aes-256-cbc'),
                        'passphrase' => $env('DB_OPS_PGBACKREST_REPO1_ENCRYPTION_PASSPHRASE'),
                    ],
                ],
            ],
            'process_max' => (int) $env('DB_OPS_PGBACKREST_PROCESS_MAX', 2),
            'resume' => (bool) $env('DB_OPS_PGBACKREST_RESUME', true),
            'start_fast' => (bool) $env('DB_OPS_PGBACKREST_START_FAST', true),
            'backup_standby' => (bool) $env('DB_OPS_PGBACKREST_BACKUP_STANDBY', false),
            'checksum_page' => (bool) $env('DB_OPS_PGBACKREST_CHECKSUM_PAGE', false),
            'delta' => (bool) $env('DB_OPS_PGBACKREST_DELTA', false),
            'command_timeout_seconds' => (int) $env('DB_OPS_PGBACKREST_TIMEOUT', $queueTimeoutDefault),
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
            'command_timeout_seconds' => (int) $env('DB_OPS_PGDUMP_TIMEOUT', $queueTimeoutDefault),
            'extra_args' => [
                'backup' => [],
                'restore' => [],
            ],
        ],
        'mysql' => [
            'class' => MysqlDriver::class,
            'dump_binary' => $env('DB_OPS_MYSQL_DUMP_BINARY', 'mysqldump'),
            'mysql_binary' => $env('DB_OPS_MYSQL_BINARY', 'mysql'),
            'mysqlbinlog_binary' => $env('DB_OPS_MYSQL_BINLOG_BINARY', 'mysqlbinlog'),
            'single_transaction' => (bool) $env('DB_OPS_MYSQL_SINGLE_TRANSACTION', true),
            'quick' => (bool) $env('DB_OPS_MYSQL_QUICK', true),
            'skip_lock_tables' => (bool) $env('DB_OPS_MYSQL_SKIP_LOCK_TABLES', true),
            'output_dir' => $env('DB_OPS_MYSQL_OUTPUT_DIR', storage_path('app/checkpoint/mysql/logical-exports')),
            'output_prefix' => $env('DB_OPS_MYSQL_OUTPUT_PREFIX', 'mysql-export'),
            'file_extension' => $env('DB_OPS_MYSQL_FILE_EXTENSION', 'sql'),
            'drill_command' => $env('DB_OPS_MYSQL_DRILL_COMMAND', ''),
            'command_timeout_seconds' => (int) $env('DB_OPS_MYSQL_TIMEOUT', $queueTimeoutDefault),
            'pitr' => [
                'binlog_files' => array_values(array_filter(array_map(
                    trim(...),
                    explode(',', (string) $env('DB_OPS_MYSQL_PITR_BINLOG_FILES', '')),
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

    'log_channel' => $env('DB_OPS_LOG_CHANNEL', 'stack'),
    'custom_operations' => [],
];
