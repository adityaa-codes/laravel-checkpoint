<?php

declare(strict_types=1);
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifiable;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupCompletedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupDrillCompletedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupDrillFailedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupFailedNotification;

/*
|--------------------------------------------------------------------------
| Laravel Checkpoint — Configuration
|--------------------------------------------------------------------------
|
| This is the published configuration file. Review every section and
| adjust values for your environment.
|
| Most users only need to set: CP_DRIVER, destination.disks, retention_days.
| All other keys have sensible defaults shown below.
|
| For binary paths (pg_dump, mysqldump, etc.), configure the `dump` key
| inside Laravel's own config/database.php per connection — just like
| spatie/laravel-backup.
|
    | Only a few env vars are used by default:
    |   CP_DRIVER    — which driver to use (postgres, mysql)
    |   CP_QUEUE_NAME — optional override for the queue name (default: checkpoint)
    |   CP_BACKUP_ARCHIVE_PASSWORD  — encryption password (null = disabled)
    |   CP_RESTORE_ALLOWED_ENVIRONMENTS — comma-separated envs where restore is allowed
    |   CP_ALERT_EMAIL — email address for alert notifications
    |   CP_SLACK_WEBHOOK — Slack webhook URL for notifications
    |   CP_TELEGRAM_BOT_TOKEN — Telegram bot token for notifications
    |   CP_TELEGRAM_CHAT_ID — Telegram chat ID for notifications
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The backup driver to use. Must match one of the keys in the `drivers`
    | section below. No default — this MUST be set.
    |
    | Supported: postgres, mysql
    |
    | Set via CP_DRIVER env var. The install wizard auto-detects this from
    | your DB_CONNECTION.
    |
    */
    'driver' => env('CP_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Destination Disks
    |--------------------------------------------------------------------------
    |
    | Laravel filesystem disk names where backup artifacts are uploaded.
    | Disks are defined in config/filesystems.php. Empty array = local only
    | (artifacts stay in the output_dir but are NOT copied to remote storage).
    |
    */
    'destination' => [
        'disks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain successful backups. Failed backups are
    | always kept for 365 days regardless of this setting.
    |
    */
    'retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Encryption is active when CP_BACKUP_ARCHIVE_PASSWORD is set to a
    | non-empty string. Leave null/unset to skip encryption.
    |
    */
    'encryption' => [
        'password' => env('CP_BACKUP_ARCHIVE_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | All backup, restore, replication, and drill operations are queued by
    | default. Configure the queue name, timeout, and locking behavior here.
    |
    | timeout — seconds before the queue worker kills the job. Must match
    |           or exceed the --timeout value passed to `queue:work`.
    | heartbeat_interval_seconds — how often the driver records a heartbeat
    |           during long-running operations (pg_dump, pg_restore, etc.).
    | heartbeat_grace_seconds — extra grace period before sweep marks a
    |           running job as timed out when heartbeats have stopped.
    |
    */
    'queue' => [
        'name' => env('CP_QUEUE_NAME', 'checkpoint'),
        'timeout' => 3600,
        'lock_store' => null,
        'unique_for' => 3660,
        'max_attempts' => 1,
        'orphan_threshold' => 10,
        'heartbeat_interval_seconds' => 30,
        'heartbeat_grace_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore Safety
    |--------------------------------------------------------------------------
    |
    | Guardrails to prevent accidental restores in production.
    |
    | allowed_environments — env names where restore is permitted
    | allowed_databases    — specific database names allowed as restore targets
    |                        (empty array = allow all databases)
    | allow_in_ci          — bypass confirmation in CI (dangerous, off by default)
    | require_verified_backup — only allow restore from verified backup signals
    |
    */
    'restore' => [
        'allowed_environments' => collect(explode(',', env('CP_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')))
            ->map(trim(...))
            ->filter(fn ($v) => $v !== '')
            ->values()
            ->all(),
        'allowed_databases' => [],
        'allow_in_ci' => false,
        'require_verified_backup' => false,
        'confirmation_phrase' => 'RESTORE',
        'blast_radius' => [
            'enabled' => true,
            'warn_score' => 50,
            'block_score' => 80,
            'weights' => [
                'environment' => 30,
                'database' => 25,
                'target' => 20,
                'verification' => 25,
            ],
        ],
        'verification' => [
            'mode' => 'moderate',
            'tables' => ['*'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gates — Policy Profiles
    |--------------------------------------------------------------------------
    |
    | Gate policies control exit codes for CI/automation. Each profile
    | defines blocker, warning, and advisory thresholds. The active profile
    | is selected by environment or override.
    |
    */
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

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Thresholds for SLO alerts and health monitoring.
    | Drills, backup freshness, and anomaly detection are policed here.
    |
    */
    'observability' => [
        'backup_drill_pass_rate_window_days' => 30,
        'backup_drill_min_pass_rate' => 100.0,
        'max_backup_drill_age_days' => 30,
        'max_last_known_good_age_hours' => 24,
        'backup_duration_min_samples' => 3,
        'backup_duration_anomaly_factor' => 2.0,
        'alert_cooldown_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    |
    | Maximum number of recent runs shown in status and report commands.
    |
    */
    'reporting' => [
        'max_recent_runs' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Output Storage
    |--------------------------------------------------------------------------
    |
    | Where and how command output is persisted.
    |
    | storage      — 'database' (inline) or 'filesystem' (externalized)
    | max_persisted_bytes — truncate output above this limit
    | filesystem.* — only used when storage is 'filesystem'
    |
    */
    'output' => [
        'max_persisted_bytes' => 1048576,
        'storage' => 'database',
        'filesystem' => [
            'disk' => 'local',
            'path_prefix' => 'checkpoint/command-output',
            'inline_bytes' => 2048,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Map notification classes to channels. Supported channels:
    | mail, slack, telegram (requires laravel-notification-channels/telegram).
    |
    | Remove a notification class or set its channels to empty to disable it.
    | Channels that are empty will cause the notification to be skipped.
    |
    */
    'notifications' => [
        'notifications' => [
            BackupFailedNotification::class => ['mail'],
            BackupCompletedNotification::class => ['mail'],
            BackupDrillFailedNotification::class => ['mail'],
            BackupDrillCompletedNotification::class => ['mail'],
        ],
        'notifiable' => Notifiable::class,
        'mail' => [
            'to' => env('CP_ALERT_EMAIL'),
        ],
        'slack' => [
            'webhook_url' => env('CP_SLACK_WEBHOOK'),
        ],
        'telegram' => [
            'bot_token' => env('CP_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('CP_TELEGRAM_CHAT_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Cron expressions for scheduled commands. Use Laravel's scheduler in
    | routes/console.php to register these — these values are only used
    | as documentation/defaults.
    |
    |   Schedule::command('checkpoint:backup')->cron('0 2 * * *');
    |   Schedule::command('checkpoint:prune')->cron('0 3 * * 0');
    |   Schedule::command('checkpoint:sweep')->cron('*\/5 * * * *');
    |
    */
    'schedule' => [
        'backup' => '0 2 * * *',
        'prune' => '0 3 * * 0',
        'sweep' => '*/5 * * * *',
        'prune_keep_backup_drill_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for checkpoint's internal tables (command_runs, backup_drill_runs,
    | restore_decision_events, verification_runs). Change this if you need
    | multiple installations in the same database.
    |
    */
    'table_prefix' => 'db_ops_',

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The Laravel log channel used for checkpoint operational logs.
    |
    */
    'log_channel' => 'stack',

    /*
    |--------------------------------------------------------------------------
    | Operations Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch to disable all checkpoint operations.
    | Set to false to prevent any backup/restore/replication from running.
    |
    */
    'operations_enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Per-driver configuration. Binary paths are resolved from
    | config/database.php connections using the `dump.dump_binary_path` key —
    | the same convention as spatie/laravel-backup.
    |
    | Example for config/database.php:
    |
    |   'pgsql' => [
    |       'dump' => [
    |           'dump_binary_path' => '/usr/bin',  // directory only
    |           'timeout' => 60 * 5,
    |       ],
    |   ],
    |
    */
    'drivers' => [

        'mysql' => [

            /*
            |----------------------------------------------------------
            | Binary paths are read from config/database.php:
            |   connections.mysql.dump.dump_binary_path
            |----------------------------------------------------------
            */

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

            /*
            |----------------------------------------------------------
            | Binary paths are read from config/database.php:
            |   connections.pgsql.dump.dump_binary_path
            |----------------------------------------------------------
            */

            'format' => 'directory',

            'jobs' => 4,

            'compress_level' => 6,

            'output_dir' => storage_path('app/checkpoint/logical-exports'),

            'output_prefix' => 'logical-export',

            'file_extension' => 'dump',

            'clean' => true,

            'create' => false,

            'drill_command' => '',

            'command_timeout_seconds' => 7200,

            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'drill' => [],
                'replication' => [],
            ],

            'physical_output_dir' => storage_path('app/checkpoint/basebackups'),

            'physical_extra_args' => [],

            'physical_wal_method' => 'stream',

            'physical_compression' => 'gzip',

            'physical_checkpoint' => 'fast',

            'physical_max_rate' => null,

            'pitr' => [
                'wal_directory' => '',
                'restore_command' => '',
                'recovery_target_action' => 'promote',
            ],

            'health_binaries' => ['pg_dump', 'pg_restore', 'pg_basebackup'],
        ],

    ],

    'temp_dir' => storage_path('app/checkpoint/tmp'),

];
