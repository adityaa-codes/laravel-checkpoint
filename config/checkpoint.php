<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Checkpoint — Database Reliability Layer
|--------------------------------------------------------------------------
|
| This is the published configuration. The package ships with sensible
| defaults and auto-detects your database driver. Customize anything
| here after running: php artisan vendor:publish --tag=checkpoint-config
|
| Minimal setup: set CP_RESTORE_ALLOWED_ENVIRONMENTS for production.
| Full setup: publish this config and tune backup strategy, retention,
| destination disks, encryption, and monitoring thresholds.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Which backup engine to use. Auto-detected from DB_CONNECTION if not set:
    |   mysql/mariadb  → mysql    (mysqldump + mysqlbinlog PITR)
    |   pgsql/postgres → postgres (pg_dump + pg_basebackup)
    |   sqlite/other   → shell    (user-provided commands)
    |
    | Supported drivers: shell, postgres, pgdump, pgbasebackup, mysql
    | Set CP_DRIVER in your .env to override auto-detection.
    |
    */
    'driver' => env('CP_DRIVER') ?: match (strtolower(trim((string) config('database.connections.'.config('database.default', 'mysql').'.driver', 'mysql')))) {
        'pgsql', 'postgres', 'postgresql' => 'postgres',
        'mysql', 'mariadb' => 'mysql',
        'sqlite' => 'shell',
        default => 'shell',
    },

    /*
    |--------------------------------------------------------------------------
    | Destination Disks
    |--------------------------------------------------------------------------
    |
    | Laravel filesystem disks where backups are streamed after creation.
    | Backups are created on local storage first (for speed), then uploaded
    | to ALL configured disks. Local files are cleaned up after upload.
    |
    | Use any disk from config/filesystems.php — local, s3, spaces, gcs.
    | Set to an empty array to keep backups local only.
    |
    */
    'destination' => [
        'disks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup / Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain successful backups. Failed backups are kept
    | for 365 days regardless. The newest backup is always retained.
    |
    | Pruning runs weekly via the scheduler. Run checkpoint:prune manually
    | to trigger immediate cleanup.
    |
    */
    'retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Archive Encryption
    |--------------------------------------------------------------------------
    |
    | Encrypt backup archives at rest before uploading to destination disks.
    | The encryption key is derived from Laravel's APP_KEY — no additional
    | secret required. Encrypted archives use AES-256-CBC.
    |
    | Restores automatically decrypt using the same key. Changing APP_KEY
    | makes existing encrypted backups unrecoverable — rotate keys carefully.
    |
    */
    'encryption' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Backups and restores run asynchronously via Laravel's queue system.
    | The timeout should be long enough for your largest database dump.
    |
    | Set CP_QUEUE_NAME in .env to isolate Checkpoint jobs from your app queue.
    | Set CP_QUEUE_TIMEOUT in .env if backups exceed 1 hour (3600s default).
    |
    */
    'queue' => [
        'name' => env('CP_QUEUE_NAME', 'db-ops'),
        'timeout' => (int) env('CP_QUEUE_TIMEOUT', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore Safety
    |--------------------------------------------------------------------------
    |
    | Restore operations can destroy data. These gates prevent accidents:
    |
    | allowed_environments — Only these environments may run restores.
    |   Default: local, testing, staging. Set to 'staging' in production.
    |   Use CP_RESTORE_ALLOWED_ENVIRONMENTS in .env (comma-separated).
    |
    | allowed_databases — Only these database names may receive restores.
    |   Default: all databases allowed. Set to 'checkpoint_shadow' to
    |   restrict restores to a dedicated shadow database.
    |   Use CP_RESTORE_ALLOWED_DATABASES in .env (comma-separated).
    |
    | Restores in local/testing skip confirmation and verified backup
    | requirements. In all other environments, confirmation is required
    | and the backup must have passed a verification check.
    |
    */
    'restore' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
        ), static fn (string $v): bool => $v !== '')),
        'allowed_databases' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_DATABASES', '')),
        ), static fn (string $v): bool => $v !== '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup / Retention
    |--------------------------------------------------------------------------
    |
    | Grandfather-Father-Son (GFS) retention strategy. No matter how you
    | configure it, the newest backup is always kept.
    |
    | keep_all_backups_for_days — Keep every backup for this many days.
    | keep_daily_backups_for_days — After that, keep one backup per day.
    | keep_weekly_backups_for_weeks — Then keep one backup per week.
    | keep_monthly_backups_for_months — Then keep one backup per month.
    | keep_yearly_backups_for_years — Then keep one backup per year.
    |
    | delete_oldest_when_using_more_megabytes_than — After cleanup, remove
    |   the oldest backup until total storage is under this limit.
    |   Set to null for unlimited storage.
    |
    */
    'cleanup' => [
        'keep_all_backups_for_days'            => 7,
        'keep_daily_backups_for_days'          => 16,
        'keep_weekly_backups_for_weeks'        => 8,
        'keep_monthly_backups_for_months'      => 4,
        'keep_yearly_backups_for_years'        => 2,
        'delete_oldest_when_using_more_megabytes_than' => 5000,
    ],

];
