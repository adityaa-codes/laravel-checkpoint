<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\AdminCatalogExportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\AdminPruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\AdminRecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CatalogExportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CheckHealthCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CheckDoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CheckPitrCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CheckReportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoBackupDiffCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoBackupFullCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoBackupIncrCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoBackupLogicalCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoDrillCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoInstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoReplicateCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoRestoreFileCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoRestoreLatestCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoRestorePitrCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoStatusCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueBackupDrillCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueLogicalBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\AdminRetentionCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PitrReadinessCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecordDrillRunCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReplicateCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RetentionPolicyCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\NotificationRouter;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
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

        $this->app->bind(function ($app): BackupDriver {
            $driver = (string) $app['config']->get('checkpoint.driver', 'shell');
            $class = $app['config']->get("checkpoint.drivers.{$driver}.class")
                ?? $this->defaultDriverClass($driver);

            if (! is_string($class) || $class === '') {
                throw new ConfigurationException(sprintf('Driver [%s] is not configured.', $driver));
            }

            $resolved = $app->make($class);

            if (! $resolved instanceof BackupDriver) {
                throw new ConfigurationException(
                    sprintf('Configured driver [%s] must implement [%s].', $class, BackupDriver::class),
                );
            }

            return $resolved;
        });

        $this->app->bind(
            ReplicationEndpointParser::class,
            ReplicationEndpointInputParser::class,
        );
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
            ->hasMigration('add_checkpoint_metadata_to_command_runs_table')
            ->hasMigration('add_orphan_recovery_claim_to_command_runs_table')
            ->hasMigration('add_heartbeat_to_command_runs_table')
            ->hasMigration('add_operator_summary_columns_to_command_runs_table')
            ->hasMigration('create_checkpoint_restore_decision_events_table')
            ->hasMigration('create_checkpoint_backup_drill_runs_table')
            ->hasMigration('create_checkpoint_verification_runs_table')
            ->hasMigration('add_reporting_indexes_to_checkpoint_tables')
            ->hasCommand(DoctorCommand::class)
            ->hasCommand(CheckDoctorCommand::class)
            ->hasCommand(CheckReportCommand::class)
            ->hasCommand(EnqueueCommand::class)
            ->hasCommand(HealthCheckCommand::class)
            ->hasCommand(InstallCommand::class)
            ->hasCommand(DoInstallCommand::class)
            ->hasCommand(DoBackupCommand::class)
            ->hasCommand(DoStatusCommand::class)
            ->hasCommand(DoBackupLogicalCommand::class)
            ->hasCommand(DoBackupFullCommand::class)
            ->hasCommand(DoBackupDiffCommand::class)
            ->hasCommand(DoBackupIncrCommand::class)
            ->hasCommand(DoRestoreLatestCommand::class)
            ->hasCommand(DoRestoreFileCommand::class)
            ->hasCommand(DoRestorePitrCommand::class)
            ->hasCommand(DoReplicateCommand::class)
            ->hasCommand(DoDrillCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasCommand(AdminPruneCommand::class)
            ->hasCommand(RecoverOrphansCommand::class)
            ->hasCommand(AdminRecoverOrphansCommand::class)
            ->hasCommand(ReportCommand::class)
            ->hasCommand(CatalogExportCommand::class)
            ->hasCommand(AdminCatalogExportCommand::class)
            ->hasCommand(PitrReadinessCommand::class)
            ->hasCommand(CheckPitrCommand::class)
            ->hasCommand(RetentionPolicyCommand::class)
            ->hasCommand(AdminRetentionCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(CheckHealthCommand::class)
            ->hasCommand(RecordDrillRunCommand::class)
            ->hasCommand(EnqueueBackupDrillCommand::class)
            ->hasCommand(EnqueueLogicalBackupCommand::class)
            ->hasCommand(ReplicateCommand::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(CommandRun::class, CommandRunPolicy::class);
        Gate::policy(BackupDrillRun::class, BackupDrillRunPolicy::class);

        $this->registerSchedules();
        $this->app->make(NotificationRouter::class)->register();

        $this->app->make(ConfigValidator::class)->validate();
    }

    private function registerSchedules(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((bool) config('checkpoint.schedule.logical_backup_enabled', true)) {
                $this->configureScheduledCommand($schedule
                    ->command('db-ops:enqueue-backup')
                    ->dailyAt((string) config('checkpoint.schedule.logical_backup_daily_at', '16:00'))
                    ->timezone((string) config('checkpoint.schedule.logical_backup_timezone', 'UTC')));
            }

            if ((bool) config('checkpoint.schedule.backup_drill_enabled', false)) {
                $this->configureScheduledCommand($schedule
                    ->command('db-ops:enqueue-drill')
                    ->dailyAt((string) config('checkpoint.schedule.backup_drill_daily_at', '03:00'))
                    ->timezone((string) config('checkpoint.schedule.backup_drill_timezone', 'UTC')));
            }

            if ((bool) config('checkpoint.schedule.health_check_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('db-ops:health-check')->everyFiveMinutes(),
                );
            }

            if ((bool) config('checkpoint.schedule.recover_orphans_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('db-ops:recover-orphans')->everyTenMinutes(),
                );
            }

            if ((bool) config('checkpoint.schedule.prune_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('db-ops:prune')->weekly(),
                );
            }
        });
    }

    private function configureScheduledCommand(ScheduledEvent $event): void
    {
        if ((bool) config('checkpoint.schedule.without_overlapping', true)) {
            $event->withoutOverlapping((int) config('checkpoint.schedule.overlap_expires_at', 180));
        }

        if ((bool) config('checkpoint.schedule.on_one_server', true)) {
            $event->onOneServer();
        }
    }

    private function defaultDriverClass(string $driver): ?string
    {
        return match ($driver) {
            'shell' => ShellCommandDriver::class,
            'postgres' => PostgresDriver::class,
            'pgbackrest' => PgBackRestDriver::class,
            'pgdump' => PgDumpDriver::class,
            'mysql' => MysqlDriver::class,
            default => null,
        };
    }
}
