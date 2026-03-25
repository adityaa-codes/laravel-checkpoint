<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests;

use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpointServiceProvider;
use AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use InteractsWithCheckpoint;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing($this->guessFactoryName(...));

        $this->runPackageMigrations();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelCheckpointServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('checkpoint', [
            'user_model' => User::class,
            'user_name_column' => 'name',
            'table_prefix' => 'db_ops_',
            'log_channel' => 'stack',
            'driver' => 'shell',
            'queue' => [
                'name' => 'db-ops',
                'max_attempts' => 1,
                'retry_after' => 3660,
                'timeout' => 3600,
                'orphan_threshold' => 10,
                'orphan_claim_timeout' => 61,
                'orphan_batch_size' => 100,
                'orphan_event_max_ids' => 50,
                'unique_for' => 3660,
                'lock_store' => 'array',
            ],
            'restore' => [
                'allowed_environments' => ['testing', 'workbench'],
                'allowed_databases' => [':memory:'],
                'require_confirmation' => false,
                'confirmation_phrase' => 'RESTORE',
                'confirmation_token' => null,
                'allow_in_ci' => true,
                'ci' => false,
                'require_verified_backup' => false,
            ],
            'observability' => [
                'max_last_known_good_age_hours' => 24,
                'backup_duration_anomaly_factor' => 2.0,
                'backup_duration_min_samples' => 3,
                'max_backup_drill_age_days' => 30,
                'backup_drill_pass_rate_window_days' => 30,
                'backup_drill_min_pass_rate' => 100.0,
            ],
            'reporting' => [
                'max_recent_runs' => 100,
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
            'drivers' => [
                'shell' => [
                    'class' => ShellCommandDriver::class,
                    'commands' => [],
                    'pgbackrest_stanza' => 'main',
                    'backup_dir' => '/tmp/checkpoint-tests',
                    'backup_prefix' => 'backup',
                    'pre_restore_snapshot' => true,
                    'command_timeout_seconds' => 5,
                ],
                'pgbackrest' => [
                    'class' => PgBackRestDriver::class,
                    'binary' => 'pgbackrest',
                    'stanza' => 'main',
                    'repo' => 1,
                    'repositories' => [
                        1 => [
                            'type' => 'posix',
                            'path' => sys_get_temp_dir().'/checkpoint-pgbackrest-repo1',
                            's3' => [
                                'bucket' => null,
                                'endpoint' => null,
                                'region' => null,
                                'key' => null,
                                'secret' => null,
                                'uri_style' => 'host',
                            ],
                            'tls' => [
                                'verify' => true,
                                'ca_file' => null,
                            ],
                            'encryption' => [
                                'enabled' => false,
                                'cipher_type' => 'aes-256-cbc',
                                'passphrase' => null,
                            ],
                        ],
                    ],
                    'process_max' => 2,
                    'resume' => true,
                    'start_fast' => true,
                    'backup_standby' => false,
                    'checksum_page' => false,
                    'delta' => false,
                    'command_timeout_seconds' => 5,
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
                    'dump_binary' => 'pg_dump',
                    'restore_binary' => 'pg_restore',
                    'format' => 'directory',
                    'jobs' => 4,
                    'compress_level' => 6,
                    'output_dir' => sys_get_temp_dir().'/checkpoint-logical-exports',
                    'output_prefix' => 'logical-export',
                    'file_extension' => 'dump',
                    'clean' => true,
                    'create' => false,
                    'command_timeout_seconds' => 5,
                    'extra_args' => [
                        'backup' => [],
                        'restore' => [],
                    ],
                ],
                'mysql' => [
                    'class' => MysqlDriver::class,
                    'dump_binary' => 'mysqldump',
                    'mysql_binary' => 'mysql',
                    'mysqlbinlog_binary' => 'mysqlbinlog',
                    'single_transaction' => true,
                    'quick' => true,
                    'skip_lock_tables' => true,
                    'output_dir' => sys_get_temp_dir().'/checkpoint-mysql-exports',
                    'output_prefix' => 'mysql-export',
                    'file_extension' => 'sql',
                    'drill_command' => '',
                    'command_timeout_seconds' => 5,
                    'pitr' => [
                        'binlog_files' => [],
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
            'schedule' => [
                'logical_backup_enabled' => true,
                'logical_backup_daily_at' => '16:00',
                'logical_backup_timezone' => 'UTC',
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
            'custom_operations' => [],
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ]);
    }

    private function runPackageMigrations(): void
    {
        if (! Schema::hasTable('db_ops_command_runs')) {
            $migration = require __DIR__.'/../database/migrations/create_checkpoint_command_runs_table.php.stub';
            $migration->up();
        }

        if (! Schema::hasColumn('db_ops_command_runs', 'backup_type')) {
            $migration = require __DIR__.'/../database/migrations/add_checkpoint_metadata_to_command_runs_table.php.stub';
            $migration->up();
        }

        if (! Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at')) {
            $migration = require __DIR__.'/../database/migrations/add_orphan_recovery_claim_to_command_runs_table.php.stub';
            $migration->up();
        }

        if (! Schema::hasColumn('db_ops_command_runs', 'driver_name')) {
            $migration = require __DIR__.'/../database/migrations/add_operator_summary_columns_to_command_runs_table.php.stub';
            $migration->up();
        }

        if (! Schema::hasTable('db_ops_backup_drill_runs')) {
            $migration = require __DIR__.'/../database/migrations/create_checkpoint_backup_drill_runs_table.php.stub';
            $migration->up();
        }

        if (! $this->hasIndex('db_ops_command_runs', 'db_ops_command_runs_verified_at_lookup_index')) {
            $migration = require __DIR__.'/../database/migrations/add_reporting_indexes_to_checkpoint_tables.php.stub';
            $migration->up();
        }
    }

    /**
     * @param  class-string<Model>  $modelName
     * @return class-string<Factory<Model>>
     */
    private function guessFactoryName(string $modelName): string
    {
        /** @var class-string<Factory<Model>> $factoryClass */
        $factoryClass = 'AdityaaCodes\\LaravelCheckpoint\\Database\\Factories\\'.class_basename($modelName).'Factory';

        return $factoryClass;
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        /** @var list<object{name:string}> $indexes */
        $indexes = \Illuminate\Support\Facades\DB::select(sprintf("PRAGMA index_list('%s')", $table));

        return collect($indexes)->contains(fn (object $index): bool => (string) $index->name === $indexName);
    }
}
