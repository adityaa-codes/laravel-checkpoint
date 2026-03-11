<?php

namespace AdityaaCodes\LaravelCheckpoint;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use AdityaaCodes\LaravelCheckpoint\Commands\LaravelCheckpointCommand;

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
            ->hasMigration('create_checkpoint_table')
            ->hasCommand(LaravelCheckpointCommand::class);
    }
}
