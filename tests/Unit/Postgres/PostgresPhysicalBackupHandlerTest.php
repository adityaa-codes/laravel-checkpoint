<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\File;

it('builds physical backup commands with configured wal method and compression', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-physical-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary physical backup path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.physical_output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.physical_wal_method', 'stream');
    config()->set('checkpoint.drivers.postgres.physical_compression', 'gzip');
    config()->set('checkpoint.drivers.postgres.physical_checkpoint', 'fast');

    $handler = app(PostgresPhysicalBackupHandler::class);
    $run = CommandRun::factory()->make([
        'operation' => 'physical_backup',
    ]);

    $metadata = $handler->plannedMetadata($run);
    $process = $handler->buildProcess($run, $metadata);

    expect($process->getCommandLine())->toContain('pg_basebackup')
        ->and($process->getCommandLine())->toContain('-Ft')
        ->and($process->getCommandLine())->toContain('-z')
        ->and($process->getCommandLine())->toContain('-X')
        ->and($process->getCommandLine())->toContain('stream')
        ->and($process->getCommandLine())->toContain('-P')
        ->and($process->getCommandLine())->toContain('--checkpoint=fast')
        ->and($metadata['artifact_path'] ?? null)->toContain($outputDir.'/backup_');
});

it('builds physical backup commands with lz4 compression', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-physical-lz4-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary physical backup path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.physical_output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.physical_compression', 'lz4');
    config()->set('checkpoint.drivers.postgres.physical_wal_method', 'fetch');
    config()->set('checkpoint.drivers.postgres.physical_checkpoint', 'spread');

    $handler = app(PostgresPhysicalBackupHandler::class);
    $run = CommandRun::factory()->make([
        'operation' => 'physical_backup',
    ]);

    $metadata = $handler->plannedMetadata($run);
    $process = $handler->buildProcess($run, $metadata);

    expect($process->getCommandLine())->toContain('--compress-lz4')
        ->and($process->getCommandLine())->toContain('-X')
        ->and($process->getCommandLine())->toContain('fetch')
        ->and($process->getCommandLine())->toContain('--checkpoint=spread');
});
