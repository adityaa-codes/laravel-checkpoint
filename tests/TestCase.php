<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests;

use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
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
                'timeout' => 3600,
                'orphan_threshold' => 10,
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
            ],
            'schedule' => [
                'prune_keep_days' => 90,
                'prune_keep_failed_days' => 365,
            ],
            'custom_operations' => [],
        ]);
    }

    private function runPackageMigrations(): void
    {
        if (! Schema::hasTable('db_ops_command_runs')) {
            $migration = require __DIR__.'/../database/migrations/create_checkpoint_command_runs_table.php.stub';
            $migration->up();
        }

        if (! Schema::hasTable('db_ops_backup_drill_runs')) {
            $migration = require __DIR__.'/../database/migrations/create_checkpoint_backup_drill_runs_table.php.stub';
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
}
