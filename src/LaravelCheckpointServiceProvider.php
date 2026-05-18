<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBackupDrillHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBackupHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeBinaryHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeConfigHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeDatabaseTableHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeQueueHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeRestorePostureHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\ComposeVerificationHealthChecksAction;
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\BackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\CatalogExportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DrillCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\MakeDriverCommand;
use AdityaaCodes\LaravelCheckpoint\Console\MigrateFromSpatieCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PitrReadinessCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReplicateCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReportCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresBackupDrillHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresDriverConfig;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationOrchestrator;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationSyncHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresRestoreTargetResolver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSnapshotService;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\HealthCheckComposer;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\GateProfileConfig;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelCheckpointServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-checkpoint')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_checkpoint_tables')
            ->hasCommands([
                DoctorCommand::class,
                HealthCheckCommand::class,
                InstallCommand::class,
                MakeDriverCommand::class,
                PruneCommand::class,
                RecoverOrphansCommand::class,
                ReportCommand::class,
                CatalogExportCommand::class,
                PitrReadinessCommand::class,
                StatusCommand::class,
                DrillCommand::class,
                BackupCommand::class,
                MigrateFromSpatieCommand::class,
                ReplicateCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(HealthCheckConfig::class, function ($app): HealthCheckConfig {
            $config = $app['config'];
            $prefix = (string) $config->get('checkpoint.table_prefix', 'db_ops_');

            return new HealthCheckConfig(
                driver: (string) $config->get('checkpoint.driver', 'shell'),
                queueName: (string) $config->get('checkpoint.queue.name', 'db-ops'),
                logChannel: (string) $config->get('checkpoint.log_channel', 'stack'),
                environment: (string) $config->get('app.env', 'production'),
                currentDatabaseName: (string) $config->get('database.connections.'.(string) $config->get('database.default', '').'.database', ''),
                lockStore: $config->get('checkpoint.queue.lock_store'),
                bin: [
                    'pgbasebackup' => (string) $config->get('checkpoint.drivers.postgres.binary', 'pg_basebackup'),
                    'pgdump_dump' => (string) $config->get('checkpoint.drivers.postgres.dump_binary', 'pg_dump'),
                    'pgdump_restore' => (string) $config->get('checkpoint.drivers.postgres.restore_binary', 'pg_restore'),
                    'mysqldump' => (string) $config->get('checkpoint.drivers.mysql.dump_binary', 'mysqldump'),
                    'mysql' => (string) $config->get('checkpoint.drivers.mysql.mysql_binary', 'mysql'),
                    'mysqlbinlog' => (string) $config->get('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog'),
                ],
                obs: [
                    'orphanThreshold' => max(1, (int) $config->get('checkpoint.queue.orphan_threshold', 10)),
                    'drillWindowDays' => max(1, (int) $config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30)),
                    'drillMinPassRate' => max(0.0, min(100.0, (float) $config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0))),
                    'maxDrillAgeDays' => max(1, (int) $config->get('checkpoint.observability.max_backup_drill_age_days', 30)),
                    'maxLastKnownGoodHours' => max(1, (int) $config->get('checkpoint.observability.max_last_known_good_age_hours', 24)),
                    'durationMinSamples' => max(2, (int) $config->get('checkpoint.observability.backup_duration_min_samples', 3)),
                    'durationAnomalyFactor' => max(1.1, (float) $config->get('checkpoint.observability.backup_duration_anomaly_factor', 2.0)),
                    'alertCooldownSeconds' => max(0, (int) $config->get('checkpoint.observability.alert_cooldown_seconds', 300)),
                ],
                restore: [
                    'allowedEnvironments' => array_values(array_filter(array_map(
                        static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                        (array) $config->get('checkpoint.restore.allowed_environments', []),
                    ), static fn (string $item): bool => $item !== '')),
                    'allowedDatabases' => array_values(array_filter(array_map(
                        static fn (mixed $item): string => is_string($item) ? trim($item) : '',
                        (array) $config->get('checkpoint.restore.allowed_databases', []),
                    ), static fn (string $item): bool => $item !== '')),
                    'allowInCi' => (bool) $config->get('checkpoint.restore.allow_in_ci', false),
                    'requireVerifiedBackup' => (bool) $config->get('checkpoint.restore.require_verified_backup', false),
                ],
                commandRunsTable: $prefix.'command_runs',
                backupDrillRunsTable: $prefix.'backup_drill_runs',
                verificationRunsTable: $prefix.'verification_runs',
                driverBinaries: array_values((array) $config->get(
                    'checkpoint.drivers.'.(string) $config->get('checkpoint.driver', 'shell').'.health_binaries',
                    [],
                )),
            );
        });
        $this->app->singleton(LaravelCheckpoint::class, fn ($app): LaravelCheckpoint => new LaravelCheckpoint(
            $app->make(EnqueueCommandRunAction::class),
        ));

        $this->bindGateProfileConfig();
        $this->bindBackupDriver();

        $this->app->bind(
            ReplicationEndpointParser::class,
            ReplicationEndpointInputParser::class,
        );

        $this->app->bind(HealthCheckComposer::class, fn ($app): HealthCheckComposer => new HealthCheckComposer(
            $app->make(HealthCheckConfig::class),
            $app->make(ComposeConfigHealthChecksAction::class),
            $app->make(ComposeBinaryHealthChecksAction::class),
            $app->make(ComposeDatabaseTableHealthChecksAction::class),
            $app->make(ComposeQueueHealthChecksAction::class),
            $app->make(ComposeRestorePostureHealthChecksAction::class),
            $app->make(ComposeBackupHealthChecksAction::class),
            $app->make(ComposeBackupDrillHealthChecksAction::class),
            $app->make(ComposeVerificationHealthChecksAction::class),
        ));
    }

    public function packageBooted(): void
    {
        Gate::policy(CommandRun::class, CommandRunPolicy::class);
        Gate::policy(BackupDrillRun::class, BackupDrillRunPolicy::class);

        $this->registerSchedules();
    }

    private function bindGateProfileConfig(): void
    {
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
    }

    private function bindBackupDriver(): void
    {
        $this->app->bind(PostgresDriverConfig::class, function ($app): PostgresDriverConfig {
            $config = $app['config'];
            $database = (string) $config->get('database.connections.'.$config->get('database.default').'.'.'database', '');

            return new PostgresDriverConfig(
                dumpBinary: (string) $config->get('checkpoint.drivers.postgres.dump_binary', 'pg_dump'),
                restoreBinary: (string) $config->get('checkpoint.drivers.postgres.restore_binary', 'pg_restore'),
                format: (string) $config->get('checkpoint.drivers.postgres.format', 'directory'),
                jobs: (int) $config->get('checkpoint.drivers.postgres.jobs', 4),
                compressLevel: (int) $config->get('checkpoint.drivers.postgres.compress_level', 6),
                outputDir: (string) $config->get('checkpoint.drivers.postgres.output_dir', storage_path('app/checkpoint/logical-exports')),
                outputPrefix: (string) $config->get('checkpoint.drivers.postgres.output_prefix', 'logical-export'),
                fileExtension: trim((string) $config->get('checkpoint.drivers.postgres.file_extension', 'dump'), '.'),
                clean: (bool) $config->get('checkpoint.drivers.postgres.clean', true),
                create: (bool) $config->get('checkpoint.drivers.postgres.create', false),
                drillCommand: (string) $config->get('checkpoint.drivers.postgres.drill_command', ''),
                extraArgsBackup: $this->filterExtraArgs($config->get('checkpoint.drivers.postgres.extra_args.backup', [])),
                extraArgsRestore: $this->filterExtraArgs($config->get('checkpoint.drivers.postgres.extra_args.restore', [])),
                extraArgsDrill: $this->filterExtraArgs($config->get('checkpoint.drivers.postgres.extra_args.drill', [])),
                commandTimeoutSeconds: (float) (int) $config->get('checkpoint.drivers.postgres.command_timeout_seconds', 7200),
                physicalBinary: (string) $config->get('checkpoint.drivers.postgres.binary', 'pg_basebackup'),
                physicalOutputDir: (string) $config->get('checkpoint.drivers.postgres.physical_output_dir', storage_path('app/checkpoint/basebackups')),
                logChannel: (string) $config->get('checkpoint.log_channel', 'stack'),
                databaseName: $database,
            );
        });

        $this->app->bind(PostgresRestoreTargetResolver::class, function ($app): PostgresRestoreTargetResolver {
            return new PostgresRestoreTargetResolver(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresSnapshotService::class),
            );
        });

        $this->app->bind(PostgresReplicationOrchestrator::class, function ($app): PostgresReplicationOrchestrator {
            return new PostgresReplicationOrchestrator(
                $app->make(PostgresDriverConfig::class),
                $app->make(ReplicationFailureSuggestionMapper::class),
                $app->make(PostgresSnapshotService::class),
            );
        });

        $this->app->bind(PostgresLogicalBackupHandler::class, function ($app): PostgresLogicalBackupHandler {
            return new PostgresLogicalBackupHandler(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresRestoreTargetResolver::class),
            );
        });

        $this->app->bind(PostgresLogicalRestoreHandler::class, function ($app): PostgresLogicalRestoreHandler {
            return new PostgresLogicalRestoreHandler(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresRestoreTargetResolver::class),
            );
        });

        $this->app->bind(PostgresReplicationSyncHandler::class, function ($app): PostgresReplicationSyncHandler {
            return new PostgresReplicationSyncHandler(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresReplicationOrchestrator::class),
            );
        });

        $this->app->bind(PostgresBackupDrillHandler::class, function ($app): PostgresBackupDrillHandler {
            return new PostgresBackupDrillHandler(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresRestoreTargetResolver::class),
            );
        });

        $this->app->bind(PostgresPhysicalBackupHandler::class, function ($app): PostgresPhysicalBackupHandler {
            return new PostgresPhysicalBackupHandler(
                $app->make(PostgresDriverConfig::class),
            );
        });

        $this->app->bind(PostgresPhysicalRestoreHandler::class, function ($app): PostgresPhysicalRestoreHandler {
            return new PostgresPhysicalRestoreHandler;
        });

        $this->app->bind(PostgresDriver::class, function ($app): PostgresDriver {
            return new PostgresDriver(
                $app->make(PostgresDriverConfig::class),
                $app->make(PostgresSnapshotService::class),
                [
                    $app->make(PostgresLogicalBackupHandler::class),
                    $app->make(PostgresLogicalRestoreHandler::class),
                    $app->make(PostgresReplicationSyncHandler::class),
                    $app->make(PostgresBackupDrillHandler::class),
                    $app->make(PostgresPhysicalBackupHandler::class),
                    $app->make(PostgresPhysicalRestoreHandler::class),
                ],
                $app->make(RestoreSafetyGuard::class),
                $app->make(PostRestoreVerificationBuilder::class),
                $app->make(CommandOutputCapture::class),
                $app->make(CommandOutputStore::class),
                $app->make(CommandLineRedactor::class),
                $app->make(BackupArtifactUploader::class),
            );
        });

        $this->app->bind(function ($app): BackupDriver {
            $config = $app['config'];
            $driver = (string) $config->get('checkpoint.driver', 'shell');
            $class = $config->get("checkpoint.drivers.{$driver}.class")
                ?? match ($driver) {
                    'shell' => ShellCommandDriver::class,
                    'postgres' => PostgresDriver::class,
                    'mysql' => MysqlDriver::class,
                    default => null,
                };

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
    }

    /**
     * @return list<string>
     */
    private function filterExtraArgs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $arg): bool => is_string($arg) && $arg !== '',
        ));
    }

    private function registerSchedules(): void
    {
        if (! (bool) config('checkpoint.operations_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ((bool) config('checkpoint.schedule.logical_backup_enabled', true)) {
                $this->configureScheduledCommand($schedule
                    ->command('checkpoint:backup')
                    ->dailyAt((string) config('checkpoint.schedule.logical_backup_daily_at', '16:00'))
                    ->timezone((string) config('checkpoint.schedule.logical_backup_timezone', 'UTC')));
            }

            if ((bool) config('checkpoint.schedule.backup_drill_enabled', false)) {
                $this->configureScheduledCommand($schedule
                    ->command('checkpoint:drill')
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
}
