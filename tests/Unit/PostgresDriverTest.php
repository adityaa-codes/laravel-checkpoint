<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresBackupDrillHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresDriverConfig;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresMetadataEnricher;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationSyncHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSnapshotService;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

it('rejects unsupported Postgres operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'unknown_op',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn () => resolvePostgresDriver()->execute(postgresContext($run), $run))
        ->toThrow(ConfigurationException::class, 'Unsupported Postgres operation [unknown_op].');
});

it('redacts pg_dump command lines before persisting and logging them', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-redact-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump redaction path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', "#!/bin/sh\nprintf 'ok'\n");
    File::chmod($binDir.'/pg_dump', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.extra_args.backup', [
        'postgresql://app:super-secret@db.internal/app?password=query-secret',
        '--password',
        'top-secret',
        '--token=abc123',
    ]);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);

    $run->refresh();

    expect($run->command_line)->toContain('postgresql://app:[REDACTED]@db.internal/app')
        ->and($run->command_line)->toContain('?password=[REDACTED]')
        ->and($run->command_line)->toContain("'--password' '[REDACTED]'")
        ->and($run->command_line)->toContain('--token=[REDACTED]')
        ->and($run->command_line)->not->toContain('super-secret')
        ->and($run->command_line)->not->toContain('query-secret')
        ->and($run->command_line)->not->toContain('top-secret')
        ->and($run->command_line)->not->toContain('abc123');
});

it('rethrows pg_dump runtime exceptions after marking the run failed', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')
        ->once()
        ->andThrow(new RuntimeException('logger runtime failure'));
    $logger->shouldReceive('error')
        ->once()
        ->with('Postgres operation crashed', Mockery::on(
            fn (array $context): bool => $context['error'] === 'logger runtime failure'
        ));

    $originalDriver = app(PostgresDriver::class);

    $driver = new PostgresDriver(
        app(PostgresDriverConfig::class),
        app(PostgresSnapshotService::class),
        [
            app(PostgresLogicalBackupHandler::class),
            app(PostgresLogicalRestoreHandler::class),
            app(PostgresReplicationSyncHandler::class),
            app(PostgresBackupDrillHandler::class),
            app(PostgresPhysicalBackupHandler::class),
            app(PostgresPhysicalRestoreHandler::class),
        ],
        app(RestoreSafetyGuard::class),
        app(PostgresMetadataEnricher::class),
        app(CommandOutputCapture::class),
        app(CommandOutputStore::class),
        app(CommandLineRedactor::class),
        app(BackupArtifactUploader::class),
        app(Dispatcher::class),
        app(Filesystem::class),
        $logger,
    );

    app()->instance(PostgresDriver::class, $driver);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $context = postgresContext($run);

    expect(fn (): mixed => resolvePostgresDriver()->execute($context, $run))
        ->toThrow(RuntimeException::class, 'logger runtime failure');

    expect($context->result)->toBeNull()
        ->and($run->exit_code)->toBeNull();

    app()->instance(PostgresDriver::class, $originalDriver);
});

it('truncates persisted pgdump output and records capture metadata', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-truncate-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump truncation path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('checkpoint.output.max_persisted_bytes', 48);
    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', "#!/bin/sh\nprintf '%s' \"$(printf 'X%.0s' \$(seq 1 80))\"\n");
    File::chmod($binDir.'/pg_dump', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);

    $run->refresh();

    expect($context->isSuccessful())->toBeTrue()
        ->and(Str::length((string) $run->command_output))->toBeLessThanOrEqual(48)
        ->and($run->command_output)->toContain('...[truncated ')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 80,
                'persisted_bytes' => Str::length((string) $run->command_output),
                'max_persisted_bytes' => 48,
            ],
        ]);
});
