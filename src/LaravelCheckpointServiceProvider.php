<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildDrillRemediationPlaybookAction;
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\CatalogExportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueBackupDrillCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueLogicalBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\MigrateFromSpatieCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PitrReadinessCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecordDrillRunCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReplicateCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RetentionPolicyCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Console\TestCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBaseBackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\HealthCheckComposer;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use AdityaaCodes\LaravelCheckpoint\Support\BinaryFinder;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\GateProfileConfig;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

final class LaravelCheckpointServiceProvider extends PackageServiceProvider
{
    public function packageRegistered(): void
    {
        $this->app->singleton(LaravelCheckpoint::class, fn ($app): LaravelCheckpoint => new LaravelCheckpoint(
            $app->make(EnqueueCommandRunAction::class),
        ));

        $this->app->bind(GateProfileConfig::class, function ($app): GateProfileConfig {
            $config = $app['config'];

            return new GateProfileConfig(
                environment: (string) $config->get('app.env', 'production'),
                overrideProfile: is_string($config->get('checkpoint.gates.override_profile')) ? $config->get('checkpoint.gates.override_profile') : null,
                defaultProfile: (string) $config->get('checkpoint.gates.default_profile', 'production'),
                environmentProfileMap: (array) $config->get('checkpoint.gates.environment_profile_map', []),
                codeMap: array_map(static fn (mixed $v) => max(0, (int) $v), (array) $config->get('checkpoint.gates.code_map', [])),
                profiles: (array) $config->get('checkpoint.gates.profiles', []),
            );
        });

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

        $this->app->bind(HealthCheckComposer::class, function ($app): HealthCheckComposer {
            $config = $app['config'];
            $environment = (string) $config->get('app.env', 'production');
            $defaultConnection = (string) $config->get('database.default', '');

            return new HealthCheckComposer(
                database: $app->make(DatabaseManager::class),
                buildDrillRemediationPlaybook: $app->make(BuildDrillRemediationPlaybookAction::class),
                driver: (string) $config->get('checkpoint.driver', 'shell'),
                queueName: (string) $config->get('checkpoint.queue.name', 'db-ops'),
                logChannel: (string) $config->get('checkpoint.log_channel', 'stack'),
                pgbasebackupBinary: (string) $config->get('checkpoint.drivers.pgbasebackup.binary', 'pg_basebackup'),
                orphanThreshold: max(1, (int) $config->get('checkpoint.queue.orphan_threshold', 10)),
                drillWindowDays: max(1, (int) $config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30)),
                backupDrillMinPassRate: max(0.0, min(100.0, (float) $config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0))),
                maxBackupDrillAgeDays: max(1, (int) $config->get('checkpoint.observability.max_backup_drill_age_days', 30)),
                maxLastKnownGoodAgeHours: max(1, (int) $config->get('checkpoint.observability.max_last_known_good_age_hours', 24)),
                backupDurationMinSamples: max(2, (int) $config->get('checkpoint.observability.backup_duration_min_samples', 3)),
                backupDurationAnomalyFactor: max(1.1, (float) $config->get('checkpoint.observability.backup_duration_anomaly_factor', 2.0)),
                alertCooldownSeconds: max(0, (int) $config->get('checkpoint.observability.alert_cooldown_seconds', 300)),
                lockStore: $config->get('checkpoint.queue.lock_store'),
                allowedEnvironments: array_values(array_filter(array_map(
                    static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                    (array) $config->get('checkpoint.restore.allowed_environments', []),
                ), static fn (string $item): bool => $item !== '')),
                allowedDatabases: array_values(array_filter(array_map(
                    static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                    (array) $config->get('checkpoint.restore.allowed_databases', []),
                ), static fn (string $item): bool => $item !== '')),
                allowInCi: (bool) $config->get('checkpoint.restore.allow_in_ci', false),
                requireVerifiedBackup: (bool) $config->get('checkpoint.restore.require_verified_backup', false),
                environment: $environment,
                currentDatabaseName: (string) $config->get('database.connections.'.$defaultConnection.'.database', ''),
                pgdumpDumpBinary: (string) $config->get('checkpoint.drivers.pgdump.dump_binary', 'pg_dump'),
                pgdumpRestoreBinary: (string) $config->get('checkpoint.drivers.pgdump.restore_binary', 'pg_restore'),
                mysqlDumpBinary: (string) $config->get('checkpoint.drivers.mysql.dump_binary', 'mysqldump'),
                mysqlBinary: (string) $config->get('checkpoint.drivers.mysql.mysql_binary', 'mysql'),
                mysqlBinlogBinary: (string) $config->get('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog'),
                binaryFinder: $app->make(BinaryFinder::class),
            );
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
            ->hasViews();

        if ($this->isExistingInstallation()) {
            $package
                ->hasMigration('create_checkpoint_command_runs_table')
                ->hasMigration('add_checkpoint_metadata_to_command_runs_table')
                ->hasMigration('add_orphan_recovery_claim_to_command_runs_table')
                ->hasMigration('add_heartbeat_to_command_runs_table')
                ->hasMigration('add_operator_summary_columns_to_command_runs_table')
                ->hasMigration('create_checkpoint_restore_decision_events_table')
                ->hasMigration('create_checkpoint_backup_drill_runs_table')
                ->hasMigration('create_checkpoint_verification_runs_table')
                ->hasMigration('add_reporting_indexes_to_checkpoint_tables');
        } else {
            $package->hasMigration('create_checkpoint_tables');
        }

        $package
            ->hasCommand(DoctorCommand::class)
            ->hasCommand(EnqueueCommand::class)
            ->hasCommand(HealthCheckCommand::class)
            ->hasCommand(InstallCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasCommand(RecoverOrphansCommand::class)
            ->hasCommand(ReportCommand::class)
            ->hasCommand(CatalogExportCommand::class)
            ->hasCommand(PitrReadinessCommand::class)
            ->hasCommand(RetentionPolicyCommand::class)
            ->hasCommand(StatusCommand::class)
            ->hasCommand(RecordDrillRunCommand::class)
            ->hasCommand(EnqueueBackupDrillCommand::class)
            ->hasCommand(EnqueueLogicalBackupCommand::class)
            ->hasCommand(MigrateFromSpatieCommand::class)
            ->hasCommand(ReplicateCommand::class)
            ->hasCommand(TestCommand::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(CommandRun::class, CommandRunPolicy::class);
        Gate::policy(BackupDrillRun::class, BackupDrillRunPolicy::class);

        $this->registerSchedules();

        $this->app->make(ConfigValidator::class)->validate();
    }

    private function isExistingInstallation(): bool
    {
        try {
            return Schema::hasTable((string) config('checkpoint.table_prefix', 'db_ops_').'command_runs');
        } catch (Throwable) {
            logger()->warning('Could not verify Checkpoint table existence; assuming existing installation.');

            return true;
        }
    }

    private function registerSchedules(): void
    {
        if (! (bool) config('checkpoint.operations_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((bool) config('checkpoint.schedule.logical_backup_enabled', true)) {
                $this->configureScheduledCommand($schedule
                    ->command('checkpoint:enqueue-backup')
                    ->dailyAt((string) config('checkpoint.schedule.logical_backup_daily_at', '16:00'))
                    ->timezone((string) config('checkpoint.schedule.logical_backup_timezone', 'UTC')));
            }

            if ((bool) config('checkpoint.schedule.backup_drill_enabled', false)) {
                $this->configureScheduledCommand($schedule
                    ->command('checkpoint:enqueue-drill')
                    ->dailyAt((string) config('checkpoint.schedule.backup_drill_daily_at', '03:00'))
                    ->timezone((string) config('checkpoint.schedule.backup_drill_timezone', 'UTC')));
            }

            if ((bool) config('checkpoint.schedule.health_check_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('checkpoint:health-check')->everyFiveMinutes(),
                );
            }

            if ((bool) config('checkpoint.schedule.recover_orphans_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('checkpoint:recover-orphans')->everyTenMinutes(),
                );
            }

            if ((bool) config('checkpoint.schedule.prune_enabled', true)) {
                $this->configureScheduledCommand(
                    $schedule->command('checkpoint:prune')->weekly(),
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
            'pgbasebackup' => PgBaseBackupDriver::class,
            'pgdump' => PgDumpDriver::class,
            'mysql' => MysqlDriver::class,
            default => null,
        };
    }
}
