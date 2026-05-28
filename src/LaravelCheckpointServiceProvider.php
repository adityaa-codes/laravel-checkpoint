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
use AdityaaCodes\LaravelCheckpoint\Console\ConfigShowCommand;
use AdityaaCodes\LaravelCheckpoint\Console\DrillCommand;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\MakeDriverCommand;
use AdityaaCodes\LaravelCheckpoint\Console\MigrateFromSpatieCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\ReplicateCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RestoreCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Console\SweepCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresBackupDrillHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresDriverConfig;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresMetadataEnricher;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPitrHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationDebugRenderer;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationOrchestrator;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationResultBuilder;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationSyncHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresRestoreTargetResolver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSnapshotService;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use AdityaaCodes\LaravelCheckpoint\Notifications\EventHandler;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CheckpointDriverManager;
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
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
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
                BackupCommand::class,
                CatalogExportCommand::class,
                ConfigShowCommand::class,
                DrillCommand::class,
                InstallCommand::class,
                MakeDriverCommand::class,
                MigrateFromSpatieCommand::class,
                PruneCommand::class,
                ReplicateCommand::class,
                RestoreCommand::class,
                StatusCommand::class,
                SweepCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        Event::subscribe(EventHandler::class);
    }

    public function packageRegistered(): void
    {
        $this->configureTablePrefix();
        $this->bindHealthCheckConfig();
        $this->app->singleton(LaravelCheckpoint::class, fn ($app): LaravelCheckpoint => new LaravelCheckpoint(
            $app->make(EnqueueCommandRunAction::class),
        ));

        $this->app->singleton(CheckpointDriverManager::class);

        $this->app->bind(BackupDriver::class, fn ($app): BackupDriver => $app[CheckpointDriverManager::class]->driver());

        $this->bindGateProfileConfig();
        $this->bindBackupDriver();

        $this->app->bind(
            ReplicationEndpointParser::class,
            ReplicationEndpointInputParser::class,
        );

        $this->bindHealthCheckComposer();
    }

    private function configureTablePrefix(): void
    {
        $prefix = (string) config('checkpoint.table_prefix', 'db_ops_');

        CommandRun::$tablePrefix = $prefix;
        BackupDrillRun::$tablePrefix = $prefix;
        RestoreDecisionEvent::$tablePrefix = $prefix;
        VerificationRun::$tablePrefix = $prefix;
    }

    private function bindHealthCheckConfig(): void
    {
        $this->app->singleton(HealthCheckConfig::class, function ($app): HealthCheckConfig {
            $config = $app['config'];
            $prefix = (string) $config->get('checkpoint.table_prefix', 'db_ops_');

            $driver = (string) $config->get('checkpoint.driver');
            $connectionName = match ($driver) {
                'postgres' => 'pgsql',
                'mysql' => 'mysql',
                default => (string) $config->get('database.default', 'mysql'),
            };
            $binaryPath = rtrim((string) $config->get('database.connections.'.$connectionName.'.dump.dump_binary_path', ''), '/');
            $prefixForBin = static fn (string $name): string => $binaryPath !== '' ? $binaryPath.'/'.$name : $name;

            return new HealthCheckConfig(
                driver: $driver,
                queueName: (string) $config->get('checkpoint.queue.name', 'checkpoint'),
                logChannel: (string) $config->get('checkpoint.log_channel', 'stack'),
                environment: (string) $config->get('app.env', 'production'),
                currentDatabaseName: (string) $config->get('database.connections.'.$config->get('database.default', '').'.database', ''),
                lockStore: $config->get('checkpoint.queue.lock_store'),
                bin: [
                    'pgbasebackup' => $prefixForBin('pg_basebackup'),
                    'pgdump_dump' => $prefixForBin('pg_dump'),
                    'pgdump_restore' => $prefixForBin('pg_restore'),
                    'mysqldump' => $prefixForBin('mysqldump'),
                    'mysql' => $prefixForBin('mysql'),
                    'mysqlbinlog' => $prefixForBin('mysqlbinlog'),
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
                    'allowedEnvironments' => $this->cleanList($config->get('checkpoint.restore.allowed_environments', [])),
                    'allowedDatabases' => $this->cleanList($config->get('checkpoint.restore.allowed_databases', [])),
                    'allowInCi' => (bool) $config->get('checkpoint.restore.allow_in_ci', false),
                    'requireVerifiedBackup' => (bool) $config->get('checkpoint.restore.require_verified_backup', false),
                ],
                commandRunsTable: $prefix.'command_runs',
                backupDrillRunsTable: $prefix.'backup_drill_runs',
                verificationRunsTable: $prefix.'verification_runs',
                driverBinaries: collect((array) $config->get(
                    'checkpoint.drivers.'.$config->get('checkpoint.driver').'.health_binaries',
                    [],
                ))->values()->all(),
            );
        });
    }

    private function bindHealthCheckComposer(): void
    {
        $this->app->bind(HealthCheckComposer::class, fn ($app): HealthCheckComposer => new HealthCheckComposer(
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

    private function bindGateProfileConfig(): void
    {
        $this->app->bind(GateProfileConfig::class, function ($app): GateProfileConfig {
            $config = $app['config'];

            return new GateProfileConfig(
                environment: (string) $config->get('app.env', 'production'),
                overrideProfile: is_string($config->get('checkpoint.gates.override_profile')) ? $config->get('checkpoint.gates.override_profile') : null,
                defaultProfile: (string) $config->get('checkpoint.gates.default_profile', 'production'),
                environmentProfileMap: (array) $config->get('checkpoint.gates.environment_profile_map', []),
                codeMap: (array) $config->get('checkpoint.gates.code_map', []),
                profiles: (array) $config->get('checkpoint.gates.profiles', []),
            );
        });
    }

    private function bindBackupDriver(): void
    {
        $this->app->bind(PostgresDriverConfig::class, function ($app): PostgresDriverConfig {
            $config = $app['config'];
            $database = (string) $config->get('database.connections.'.$config->get('database.default').'.'.'database', '');

            return PostgresDriverConfig::fromArray(
                $config->get('checkpoint.drivers.postgres', []),
                new Filesystem,
                $database,
                $config,
            );
        });

        $this->app->bind(PostgresRestoreTargetResolver::class, fn ($app): PostgresRestoreTargetResolver => new PostgresRestoreTargetResolver(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresSnapshotService::class),
            new Filesystem,
        ));

        $this->app->bind(PostgresReplicationDebugRenderer::class, PostgresReplicationDebugRenderer::class);

        $this->app->bind(PostgresReplicationResultBuilder::class, fn ($app): PostgresReplicationResultBuilder => new PostgresReplicationResultBuilder(
            $app->make(PostgresReplicationDebugRenderer::class),
            $app->make(ReplicationFailureSuggestionMapper::class),
        ));

        $this->app->bind(PostgresReplicationOrchestrator::class, fn ($app): PostgresReplicationOrchestrator => new PostgresReplicationOrchestrator(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresSnapshotService::class),
            new Filesystem,
            $app->make(PostgresReplicationResultBuilder::class),
        ));

        $this->app->bind(PostgresLogicalBackupHandler::class, fn ($app): PostgresLogicalBackupHandler => new PostgresLogicalBackupHandler(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresRestoreTargetResolver::class),
        ));

        $this->app->bind(PostgresLogicalRestoreHandler::class, fn ($app): PostgresLogicalRestoreHandler => new PostgresLogicalRestoreHandler(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresRestoreTargetResolver::class),
        ));

        $this->app->bind(PostgresReplicationSyncHandler::class, fn ($app): PostgresReplicationSyncHandler => new PostgresReplicationSyncHandler(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresReplicationOrchestrator::class),
        ));

        $this->app->bind(PostgresBackupDrillHandler::class, fn ($app): PostgresBackupDrillHandler => new PostgresBackupDrillHandler(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresRestoreTargetResolver::class),
        ));

        $this->app->bind(PostgresPhysicalBackupHandler::class, fn ($app): PostgresPhysicalBackupHandler => new PostgresPhysicalBackupHandler(
            $app->make(PostgresDriverConfig::class),
        ));

        $this->app->bind(PostgresPhysicalRestoreHandler::class, fn ($app): PostgresPhysicalRestoreHandler => new PostgresPhysicalRestoreHandler(
            new Filesystem,
        ));

        $this->app->bind(PostgresPitrHandler::class, fn ($app): PostgresPitrHandler => new PostgresPitrHandler(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresLogicalRestoreHandler::class),
            $app->make(PostgresRestoreTargetResolver::class),
            $app->make(PostgresSnapshotService::class),
            new Filesystem,
        ));

        $this->app->bind(PostgresMetadataEnricher::class, fn ($app): PostgresMetadataEnricher => new PostgresMetadataEnricher(
            $app->make(PostgresSnapshotService::class),
            $app->make(PostgresDriverConfig::class),
            $app->make(PostRestoreVerificationBuilder::class),
        ));

        $this->app->bind(PostgresDriver::class, fn ($app): PostgresDriver => new PostgresDriver(
            $app->make(PostgresDriverConfig::class),
            $app->make(PostgresSnapshotService::class),
            [
                $app->make(PostgresLogicalBackupHandler::class),
                $app->make(PostgresLogicalRestoreHandler::class),
                $app->make(PostgresReplicationSyncHandler::class),
                $app->make(PostgresBackupDrillHandler::class),
                $app->make(PostgresPhysicalBackupHandler::class),
                $app->make(PostgresPhysicalRestoreHandler::class),
                $app->make(PostgresPitrHandler::class),
            ],
            $app->make(RestoreSafetyGuard::class),
            $app->make(PostgresMetadataEnricher::class),
            $app->make(CommandOutputCapture::class),
            $app->make(CommandOutputStore::class),
            $app->make(CommandLineRedactor::class),
            $app->make(BackupArtifactUploader::class),
            $app->make(Dispatcher::class),
            new Filesystem,
            $app->make(LoggerInterface::class),
        ));
    }

    /**
     * @return list<string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && Str::trim($item) !== '') {
                $result[] = Str::trim($item);
            }
        }

        return $result;
    }
}
