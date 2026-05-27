<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Facades\File;

use function Laravel\Prompts\note;

final class PublishFullConfigCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:publish:full-config {--force : Overwrite existing config file.}';

    protected $description = 'Publish the full reference configuration with all keys documented.';

    public function handle(): int
    {
        $destination = config_path('checkpoint.php');

        if (File::exists($destination) && ! (bool) $this->option('force')) {
            $this->promptError('Config file already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        $content = $this->fullReferenceConfig();

        File::put($destination, $content);

        $this->promptInfo('Published full reference config to config/checkpoint.php');
        note('Review and tune values for your environment.');

        return self::SUCCESS;
    }

    private function fullReferenceConfig(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Checkpoint — Full Reference Configuration
|--------------------------------------------------------------------------
|
| This is the FULL reference config with every key documented.
| Run `php artisan checkpoint:publish:full-config --force` to regenerate.
|
| Most users only need to set: driver, destination.disks, retention_days.
| All other keys have sensible defaults.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Supported: shell, postgres, mysql
    | Set via CP_DRIVER env var. Auto-detected during install.
    |
    */
    'driver' => env('DB_OPS_DRIVER', 'shell'),

    /*
    |--------------------------------------------------------------------------
    | Destination Disks
    |--------------------------------------------------------------------------
    |
    | Laravel filesystem disks for backup upload.
    | Empty array = local only.
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
    | Days to retain successful backups. Failed backups kept 365 days.
    |
    */
    'retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | AES-256-CBC archive encryption. Key derived from APP_KEY.
    |
    */
    'encryption' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'name' => env('DB_OPS_QUEUE_NAME', 'db-ops'),
        'timeout' => env('DB_OPS_QUEUE_TIMEOUT', 3600),
        'lock_store' => null,
        'orphan_threshold' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore Safety
    |--------------------------------------------------------------------------
    */
    'restore' => [
        'allowed_environments' => collect(explode(',', env('DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')))->map(trim(...))->filter(fn ($v) => $v !== '')->values()->all(),
        'allowed_databases' => collect(explode(',', env('DB_OPS_RESTORE_ALLOWED_DATABASES', '')))->map(trim(...))->filter(fn ($v) => $v !== '')->values()->all(),
        'allow_in_ci' => false,
        'require_verified_backup' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gates (Policy Profiles)
    |--------------------------------------------------------------------------
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
    | Observability Thresholds
    |--------------------------------------------------------------------------
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
    */
    'reporting' => [
        'max_recent_runs' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------------
    */
    'output' => [
        'max_persisted_bytes' => 1048576,
        'storage' => 'database',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'channels' => [],
        'on_success' => false,
        'on_failure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'backup' => '0 2 * * *',
        'prune' => '0 3 * * 0',
        'sweep' => '*/5 * * * *',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    */
    'table_prefix' => 'db_ops_',

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    */
    'log_channel' => 'stack',

    /*
    |--------------------------------------------------------------------------
    | Operations Enabled
    |--------------------------------------------------------------------------
    */
    'operations_enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
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
            'dump_binary' => 'mysqldump',
            'mysql_binary' => 'mysql',
            'mysqlbinlog_binary' => 'mysqlbinlog',
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
        ],
        'postgres' => [
            'dump_binary' => 'pg_dump',
            'restore_binary' => 'pg_restore',
            'binary' => 'pg_basebackup',
            'format' => 'directory',
            'jobs' => 4,
            'compress_level' => 6,
            'clean' => true,
            'create' => false,
            'command_timeout_seconds' => 7200,
            'output_dir' => storage_path('app/checkpoint/logical-exports'),
            'output_prefix' => 'logical-export',
            'file_extension' => 'dump',
            'drill_command' => '',
            'extra_args' => [
                'backup' => [],
                'restore' => [],
                'drill' => [],
            ],
            'physical_output_dir' => storage_path('app/checkpoint/basebackups'),
            'physical_wal_method' => 'stream',
            'physical_compression' => 'gzip',
            'physical_checkpoint' => 'fast',
            'health_binaries' => [
                ['code' => 'pg_dump', 'label' => 'pg_dump', 'binary' => 'pg_dump'],
                ['code' => 'pg_restore', 'label' => 'pg_restore', 'binary' => 'pg_restore'],
                ['code' => 'pg_basebackup', 'label' => 'pg_basebackup', 'binary' => 'pg_basebackup'],
            ],
        ],
    ],

];
PHP;
    }
}
