<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

it('builds directory format pg_dump commands with parallel jobs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgdump-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary output path.');
    }

    unlink($outputDir);

    config()->set('checkpoint.drivers.pgdump.dump_binary', 'pg_dump');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 8);
    config()->set('checkpoint.drivers.pgdump.compress_level', 3);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'huge-export');
    config()->set('checkpoint.drivers.pgdump.extra_args.backup', ['--no-owner']);

    $run = CommandRun::factory()->make([
        'id' => 42,
        'operation' => 'logical_backup',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process)->toBeInstanceOf(Process::class)
        ->and($process->getCommandLine())->toContain('pg_dump')
        ->and($process->getCommandLine())->toContain('--format=directory')
        ->and($process->getCommandLine())->toContain('--jobs=8')
        ->and($process->getCommandLine())->toContain('--compress=3')
        ->and($process->getCommandLine())->toContain('--file='.$outputDir.'/huge-export-42')
        ->and($process->getCommandLine())->toContain('--no-owner');
});

it('builds restore commands from the latest logical export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $older = $outputDir.'/logical-export-10';
    $newer = $outputDir.'/logical-export-11';

    mkdir($older, 0755, true);
    mkdir($newer, 0755, true);
    touch($older, time() - 60);
    touch($newer, time());

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'logical-export');
    config()->set('checkpoint.drivers.pgdump.clean', true);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain('pg_restore')
        ->and($process->getCommandLine())->toContain('--format=directory')
        ->and($process->getCommandLine())->toContain('--jobs=4')
        ->and($process->getCommandLine())->toContain('--clean')
        ->and($process->getCommandLine())->toContain($newer);
});

it('resolves relative restore arguments within the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-file-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.jobs', 1);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');
    config()->set('checkpoint.drivers.pgdump.extra_args.restore', ['--if-exists']);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain('--format=custom')
        ->and($process->getCommandLine())->toContain('--if-exists')
        ->and($process->getCommandLine())->toContain($outputDir.'/nightly-2026-03-11.archive');
});

it('rejects unsupported pg_dump operations', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_info',
    ]);

    expect(fn (): Process => buildPgDumpProcess(new PgDumpDriver, $run))
        ->toThrow(ConfigurationException::class, 'Unsupported pg_dump operation [pgbackrest_info].');
});

it('records metadata for successful logical exports', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgdump-exec-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump execution path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.pgdump.dump_binary', fakePgDumpScript(<<<'SH'
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
SH));
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgDumpDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->backup_type)->toBe('logical_export')
        ->and($run->artifact_path)->toBe($outputDir.'/logical-export-'.$run->getKey())
        ->and($run->backup_size_bytes)->toBe(7)
        ->and($run->verification_state)->toBe('not_applicable')
        ->and($run->last_known_good_at)->not->toBeNull()
        ->and($run->duration_seconds)->toBeInt()
        ->and($run->metadata)->toMatchArray([
            'driver' => 'pgdump',
            'format' => 'directory',
            'jobs' => 4,
        ]);

    Event::assertDispatched(BackupCompleted::class);
    Event::assertNotDispatched(BackupFailed::class);
});

it('blocks logical restores when no verified backup signal is available', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-guard-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore guard path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);
    mkdir($outputDir.'/logical-export-12', 0755, true);

    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new PgDumpDriver)->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

it('blocks logical_restore_latest when the newest export lacks a matching verified signal', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-guard-latest-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore guard path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $older = $outputDir.'/logical-export-11';
    $newer = $outputDir.'/logical-export-12';

    mkdir($older, 0755, true);
    mkdir($newer, 0755, true);
    touch($older, time() - 60);
    touch($newer, time());

    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $older,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new PgDumpDriver)->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

function buildPgDumpProcess(PgDumpDriver $driver, CommandRun $run): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run);

    return $process;
}

function fakePgDumpScript(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'pgdump-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump test script.');
    }

    file_put_contents($path, $contents."\n");
    chmod($path, 0755);

    return $path;
}
