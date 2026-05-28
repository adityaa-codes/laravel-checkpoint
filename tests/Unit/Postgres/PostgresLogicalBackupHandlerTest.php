<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('builds directory format pg_dump commands with parallel jobs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary output path.');
    }

    File::delete($outputDir);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 8);
    config()->set('checkpoint.drivers.postgres.compress_level', 3);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'huge-export');
    config()->set('checkpoint.drivers.postgres.extra_args.backup', ['--no-owner']);

    $handler = resolvePostgresLogicalBackupHandler();
    $run = CommandRun::factory()->make([
        'id' => 42,
        'operation' => 'logical_backup',
    ]);

    $process = $handler->buildProcess($run, []);

    expect($process)->toBeInstanceOf(Process::class)
        ->and($process->getCommandLine())->toContain('pg_dump')
        ->and($process->getCommandLine())->toContain('--format=directory')
        ->and($process->getCommandLine())->toContain('--jobs=8')
        ->and($process->getCommandLine())->toContain('--compress=3')
        ->and($process->getCommandLine())->toContain('--file='.$outputDir.'/huge-export-42')
        ->and($process->getCommandLine())->toContain('--no-owner');
});

it('includes connection parameters in pg_dump commands when configured', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-connection-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary connection test path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('database.connections.pgsql.host', 'db.internal');
    config()->set('database.connections.pgsql.port', 5433);
    config()->set('database.connections.pgsql.username', 'backup_user');

    $handler = resolvePostgresLogicalBackupHandler();
    $run = CommandRun::factory()->make([
        'id' => 99,
        'operation' => 'logical_backup',
    ]);

    $process = $handler->buildProcess($run, []);

    expect($process->getCommandLine())->toContain('--host=db.internal')
        ->and($process->getCommandLine())->toContain('--port=5433')
        ->and($process->getCommandLine())->toContain('--username=backup_user');
});

it('records metadata for successful logical exports', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-exec-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump execution path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $binDir = sys_get_temp_dir().'/checkpoint-pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', <<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --file=*)
      target="${arg#--file=}"
      mkdir -p "$target"
      printf 'segment' > "$target/part-0001.bin"
      ;;
  esac
done
printf 'export complete'
SH);
    File::chmod($binDir.'/pg_dump', 0755);

    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.jobs', 4);
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
        ->and($run->backup_type)->toBe('logical_export')
        ->and($run->artifact_path)->toBe($outputDir.'/logical-export-'.$run->getKey())
        ->and($run->backup_size_bytes)->toBe(7)
        ->and($run->verification_state)->toBe('not_applicable')
        ->and($run->last_known_good_at)->not->toBeNull()
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'format' => 'directory',
            'jobs' => 4,
        ]);

    expect($run->metadata['artifact_snapshot'] ?? null)->toBeArray()
        ->and($run->metadata['artifact_snapshot']['path'] ?? null)->toBe($outputDir.'/logical-export-'.$run->getKey())
        ->and($run->metadata['artifact_snapshot']['file_type'] ?? null)->toBe('directory');
});
