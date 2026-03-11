<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Console\EnqueueCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueLogicalBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecordDrillRunCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelCheckpointServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-checkpoint')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_checkpoint_command_runs_table')
            ->hasMigration('create_checkpoint_backup_drill_runs_table')
            ->hasCommand(EnqueueCommand::class)
            ->hasCommand(HealthCheckCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasCommand(RecoverOrphansCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(RecordDrillRunCommand::class)
            ->hasCommand(EnqueueLogicalBackupCommand::class);
    }
}
