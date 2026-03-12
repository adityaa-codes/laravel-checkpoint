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
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
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

it('prefers the newest tracked logical export over a filesystem scan for restore_latest', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-tracked-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $olderTracked = $outputDir.'/logical-export-10';
    $newerTracked = $outputDir.'/logical-export-11';
    $filesystemNewest = $outputDir.'/logical-export-99';

    mkdir($olderTracked, 0755, true);
    mkdir($newerTracked, 0755, true);
    mkdir($filesystemNewest, 0755, true);
    touch($olderTracked, time() - 120);
    touch($newerTracked, time() - 60);
    touch($filesystemNewest, time());

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'logical-export');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $olderTracked,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'finished_at' => now()->subMinutes(5),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $newerTracked,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'finished_at' => now()->subMinute(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain($newerTracked)
        ->and($process->getCommandLine())->not->toContain($filesystemNewest);
});

it('falls back to the next valid tracked export when the newest tracked artifact is stale', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-tracked-fallback-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $validTracked = $outputDir.'/logical-export-10';
    $staleTracked = $outputDir.'/logical-export-11';

    mkdir($validTracked, 0755, true);

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'logical-export');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $validTracked,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'finished_at' => now()->subMinutes(5),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $staleTracked,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'finished_at' => now()->subMinute(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain($validTracked)
        ->and($process->getCommandLine())->not->toContain($staleTracked);
});

it('rejects logical_restore_latest when the newest matching export escapes via symlink', function (): void {
    if (! function_exists('symlink')) {
        test()->markTestSkipped('Symlinks are not supported in this environment.');
    }

    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-latest-link-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $safeExport = $outputDir.'/logical-export-10';
    $outsideExport = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-external-dir-');

    if ($outsideExport === false) {
        throw new RuntimeException('Unable to allocate an external export path.');
    }

    unlink($outsideExport);
    mkdir($outsideExport, 0755, true);
    mkdir($safeExport, 0755, true);

    $linkedExport = $outputDir.'/logical-export-11';
    symlink($outsideExport, $linkedExport);
    touch($safeExport, time() - 60);
    touch($outsideExport, time());

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'logical-export');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    expect(fn (): Process => buildPgDumpProcess(new PgDumpDriver, $run))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured pg_dump output directory.',
            $linkedExport,
        ));
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

    touch($outputDir.'/nightly-2026-03-11.archive');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain('--format=custom')
        ->and($process->getCommandLine())->toContain('--if-exists')
        ->and($process->getCommandLine())->toContain($outputDir.'/nightly-2026-03-11.archive');
});

it('accepts absolute restore paths only when they stay inside the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-abs-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.archive';
    touch($target);

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => $target,
    ]);

    $process = buildPgDumpProcess(new PgDumpDriver, $run);

    expect($process->getCommandLine())->toContain($target);
});

it('rejects restore paths that escape the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-escape-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);
    touch(dirname($outputDir).'/outside.archive');

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => '../outside',
    ]);

    expect(fn (): Process => buildPgDumpProcess(new PgDumpDriver, $run))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured pg_dump output directory.',
            $outputDir.'/../outside.archive',
        ));
});

it('rejects absolute restore paths outside the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-outside-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    $outsideTargetBase = tempnam(sys_get_temp_dir(), 'checkpoint-pgdump-outside-artifact-');

    if ($outsideTargetBase === false) {
        throw new RuntimeException('Unable to allocate an external restore artifact.');
    }

    $outsideTarget = $outsideTargetBase.'.archive';
    rename($outsideTargetBase, $outsideTarget);

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => $outsideTarget,
    ]);

    expect(fn (): Process => buildPgDumpProcess(new PgDumpDriver, $run))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured pg_dump output directory.',
            $outsideTarget,
        ));
});

it('rejects restore commands when the validated restore file changes before execution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-race-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.archive';
    file_put_contents($target, 'original');

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');

    $driver = new PgDumpDriver;
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $plannedMetadata = plannedPgDumpMetadata($driver, $run);

    unlink($target);
    file_put_contents($target, 'replacement');

    expect(fn (): Process => buildPgDumpProcessWithMetadata($driver, $run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical restore target [%s] changed after validation and must be selected again.',
            $target,
        ));
});

it('rejects restore commands when a validated directory export changes before execution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-dir-race-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-directory path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $target = $outputDir.'/logical-export-42';
    mkdir($target, 0755, true);
    file_put_contents($target.'/toc.dat', 'original');

    config()->set('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.pgdump.format', 'directory');
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.output_prefix', 'logical-export');

    $driver = new PgDumpDriver;
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'logical-export-42',
    ]);

    $plannedMetadata = plannedPgDumpMetadata($driver, $run);

    file_put_contents($target.'/toc.dat', 'mutated');

    expect(fn (): Process => buildPgDumpProcessWithMetadata($driver, $run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical restore target [%s] changed after validation and must be selected again.',
            $target,
        ));
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
        'finished_at' => now()->subMinutes(5),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $newer,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'finished_at' => now()->subMinute(),
    ]);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new PgDumpDriver)->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

it('records restore audit metadata for pgdump restore runs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgdump-restore-audit-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump restore audit path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    config()->set('checkpoint.drivers.pgdump.restore_binary', fakePgDumpScript("#!/bin/sh\nprintf 'restore complete'\n"));
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.jobs', 1);
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.file_extension', 'archive');

    touch($outputDir.'/nightly-2026-03-11.archive');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgDumpDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->metadata)->toMatchArray([
            'driver' => 'pgdump',
            'format' => 'custom',
            'jobs' => 1,
            'restored_via' => 'pg_restore',
            'restore_audit' => [
                'environment' => (string) config('app.env'),
                'database' => ':memory:',
                'target' => $outputDir.'/nightly-2026-03-11.archive',
                'confirmation_required' => true,
                'confirmation_satisfied_via' => 'token',
                'verified_backup_required' => false,
                'verified_signal_run_id' => null,
                'verified_signal_operation' => null,
                'verified_signal_backup_label' => null,
                'verified_signal_artifact_path' => null,
                'verified_signal_last_known_good_at' => null,
            ],
        ]);
});

it('redacts pg_dump command lines before persisting and logging them', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgdump-redact-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump redaction path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.pgdump.dump_binary', fakePgDumpScript("#!/bin/sh\nprintf 'ok'\n"));
    config()->set('checkpoint.drivers.pgdump.output_dir', $outputDir);
    config()->set('checkpoint.drivers.pgdump.extra_args.backup', [
        'postgresql://app:super-secret@db.internal/app',
        'password=top-secret',
        '--token=abc123',
    ]);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')
        ->once()
        ->with('Starting pg_dump operation', Mockery::on(
            fn (array $context): bool => str_contains((string) $context['command_line'], 'postgresql://app:[REDACTED]@db.internal/app')
                && str_contains((string) $context['command_line'], 'password=[REDACTED]')
                && str_contains((string) $context['command_line'], '--token=[REDACTED]')
                && ! str_contains((string) $context['command_line'], 'super-secret')
                && ! str_contains((string) $context['command_line'], 'top-secret')
                && ! str_contains((string) $context['command_line'], 'abc123')
        ));
    $logger->shouldReceive('info')
        ->once()
        ->with('Completed pg_dump operation', Mockery::type('array'));
    Log::shouldReceive('channel')->twice()->andReturn($logger);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgDumpDriver)->execute($run);

    $run->refresh();

    expect($run->command_line)->toContain('postgresql://app:[REDACTED]@db.internal/app')
        ->and($run->command_line)->toContain('password=[REDACTED]')
        ->and($run->command_line)->toContain('--token=[REDACTED]')
        ->and($run->command_line)->not->toContain('super-secret')
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
        ->with('pg_dump operation crashed', Mockery::on(
            fn (array $context): bool => $context['error'] === 'logger runtime failure'
        ));
    Log::shouldReceive('channel')->twice()->andReturn($logger);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new PgDumpDriver)->execute($run))
        ->toThrow(RuntimeException::class, 'logger runtime failure');

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(-1);
});

function buildPgDumpProcess(PgDumpDriver $driver, CommandRun $run): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run, []);

    return $process;
}

/**
 * @param  array<string, mixed>  $plannedMetadata
 */
function buildPgDumpProcessWithMetadata(PgDumpDriver $driver, CommandRun $run, array $plannedMetadata): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run, $plannedMetadata);

    return $process;
}

/**
 * @return array<string, mixed>
 */
function plannedPgDumpMetadata(PgDumpDriver $driver, CommandRun $run): array
{
    $method = new ReflectionMethod($driver, 'plannedMetadata');

    /** @var array<string, mixed> $metadata */
    $metadata = $method->invoke($driver, $run);

    return $metadata;
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
