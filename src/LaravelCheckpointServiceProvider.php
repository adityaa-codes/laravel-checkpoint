<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueLogicalBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecordDrillRunCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelCheckpointServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->singleton(LaravelCheckpoint::class, fn ($app): LaravelCheckpoint => new LaravelCheckpoint(
            $app->make(EnqueueCommandRunAction::class),
        ));

        $this->app->bind(BackupDriver::class, function ($app): BackupDriver {
            $driver = (string) $app['config']->get('checkpoint.driver', 'shell');
            $class = $app['config']->get("checkpoint.drivers.{$driver}.class");

            return $app->make((string) $class);
        });
    }

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
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_checkpoint_command_runs_table')
            ->hasMigration('create_checkpoint_backup_drill_runs_table')
            ->hasCommand(DoctorCommand::class)
            ->hasCommand(EnqueueCommand::class)
            ->hasCommand(HealthCheckCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasCommand(RecoverOrphansCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(RecordDrillRunCommand::class)
            ->hasCommand(EnqueueLogicalBackupCommand::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(CommandRun::class, CommandRunPolicy::class);
        Gate::policy(BackupDrillRun::class, BackupDrillRunPolicy::class);

        $this->registerSchedules();

        if ($this->app->environment('production')) {
            return;
        }

        $this->app->make(ConfigValidator::class)->validate();
    }

    private function registerSchedules(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((bool) config('checkpoint.schedule.logical_backup_enabled', true)) {
                $schedule
                    ->command('db-ops:enqueue-backup')
                    ->dailyAt((string) config('checkpoint.schedule.logical_backup_daily_at', '16:00'))
                    ->timezone((string) config('checkpoint.schedule.logical_backup_timezone', 'UTC'));
            }

            if ((bool) config('checkpoint.schedule.health_check_enabled', true)) {
                $schedule->command('db-ops:health-check')->everyFiveMinutes();
            }

            if ((bool) config('checkpoint.schedule.recover_orphans_enabled', true)) {
                $schedule->command('db-ops:recover-orphans')->everyTenMinutes();
            }

            if ((bool) config('checkpoint.schedule.prune_enabled', true)) {
                $schedule->command('db-ops:prune')->weekly();
            }
        });
    }
}
