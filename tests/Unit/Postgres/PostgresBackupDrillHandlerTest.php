<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresBackupDrillHandler;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\File;

it('builds default drill command using pg_restore --list', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-drill-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary drill test path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);
    touch($outputDir.'/logical-export-1.dump');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');
    config()->set('checkpoint.drivers.postgres.drill_command', '');

    $handler = app(PostgresBackupDrillHandler::class);
    $run = CommandRun::factory()->make([
        'operation' => 'backup_drill',
    ]);

    $metadata = $handler->plannedMetadata($run);
    $process = $handler->buildProcess($run, $metadata);

    expect($process->getCommandLine())->toContain('pg_restore')
        ->and($process->getCommandLine())->toContain('--list')
        ->and($process->getCommandLine())->toContain($outputDir.'/logical-export-1.dump');
});

it('builds custom drill command with placeholder substitution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-drill-custom-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary custom drill test path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);
    touch($outputDir.'/logical-export-2.dump');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');
    config()->set('checkpoint.drivers.postgres.drill_command', 'pg_restore --list --dbname={db} {target}');
    config()->set('database.connections.'.config('database.default').'.database', 'test_db');

    $handler = app(PostgresBackupDrillHandler::class);
    $run = CommandRun::factory()->make([
        'operation' => 'backup_drill',
    ]);

    $metadata = $handler->plannedMetadata($run);
    $process = $handler->buildProcess($run, $metadata);

    expect($process->getCommandLine())->toContain('--dbname=test_db')
        ->and($process->getCommandLine())->toContain($outputDir.'/logical-export-2.dump');
});
