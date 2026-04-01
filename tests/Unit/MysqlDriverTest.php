<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

it('builds mysqldump backup commands from structured config', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-backup-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql output path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', 'mysqldump');
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.output_prefix', 'nightly');
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');
    config()->set('checkpoint.drivers.mysql.single_transaction', true);
    config()->set('checkpoint.drivers.mysql.quick', true);
    config()->set('checkpoint.drivers.mysql.skip_lock_tables', true);
    config()->set('checkpoint.drivers.mysql.extra_args.backup', ['--set-gtid-purged=OFF']);

    $run = CommandRun::factory()->make([
        'id' => 42,
        'operation' => 'logical_backup',
    ]);

    $process = buildMysqlProcess(new MysqlDriver, $run);

    expect($process)->toBeInstanceOf(Process::class)
        ->and($process->getCommandLine())->toContain('mysqldump')
        ->and($process->getCommandLine())->toContain('--databases')
        ->and($process->getCommandLine())->toContain('--result-file='.$outputDir.'/nightly-42.sql')
        ->and($process->getCommandLine())->toContain('--single-transaction')
        ->and($process->getCommandLine())->toContain('--quick')
        ->and($process->getCommandLine())->toContain('--skip-lock-tables')
        ->and($process->getCommandLine())->toContain('--set-gtid-purged=OFF');
});

it('builds mysqlbinlog commands for pitr restore targets', function (): void {
    $tempDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-tempdir-');

    if ($tempDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql temp directory path.');
    }

    unlink($tempDir);
    mkdir($tempDir, 0755, true);

    config()->set('checkpoint.temp_dir', $tempDir);
    config()->set('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog');
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', ['/var/lib/mysql/binlog.000001', '/var/lib/mysql/binlog.000002']);
    config()->set('checkpoint.drivers.mysql.extra_args.pitr_binlog', ['--read-from-remote-server']);

    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => '2026-03-24 11:00:00',
    ]);

    $process = buildMysqlProcess(new MysqlDriver, $run, [
        'restore_target' => '2026-03-24 11:00:00',
    ]);

    expect($process->getCommandLine())->toContain('mysqlbinlog')
        ->and($process->getCommandLine())->toContain('--stop-datetime=2026-03-24 11:00:00')
        ->and($process->getCommandLine())->toContain('--read-from-remote-server')
        ->and($process->getCommandLine())->toContain('/var/lib/mysql/binlog.000001')
        ->and($process->getCommandLine())->toContain('/var/lib/mysql/binlog.000002');
});

it('rejects mysql temp directory paths that cannot be used for temp files', function (): void {
    $tempDirFile = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-temp-file-');

    if ($tempDirFile === false) {
        throw new RuntimeException('Unable to allocate a temporary checkpoint temp-dir file path.');
    }

    config()->set('checkpoint.temp_dir', $tempDirFile);

    $driver = new MysqlDriver;
    $method = new ReflectionMethod($driver, 'tempDir');

    expect(fn (): mixed => $method->invoke($driver))
        ->toThrow(ConfigurationException::class, sprintf('Unable to create checkpoint temp directory [%s].', $tempDirFile));
});

it('rejects restore paths outside the configured mysql output directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-restore-escape-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql restore path.');
    }

    $outsideTargetBase = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-outside-');

    if ($outsideTargetBase === false) {
        throw new RuntimeException('Unable to allocate an external mysql restore artifact.');
    }

    $outsideTarget = $outsideTargetBase.'.sql';
    rename($outsideTargetBase, $outsideTarget);

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.mysql_binary', 'mysql');
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => $outsideTarget,
    ]);

    expect(fn (): Process => buildMysqlProcess(new MysqlDriver, $run))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured mysql output directory.',
            $outsideTarget,
        ));
});

it('rejects restore commands when the validated mysql restore file changes before execution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-restore-race-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql restore-file path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.sql';
    file_put_contents($target, 'original');

    config()->set('checkpoint.drivers.mysql.mysql_binary', 'mysql');
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');

    $driver = new MysqlDriver;
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $plannedMetadata = plannedMysqlMetadata($driver, $run);

    unlink($target);
    file_put_contents($target, 'replacement');

    expect(fn (): Process => buildMysqlProcess($driver, $run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical restore target [%s] changed after validation and must be selected again.',
            $target,
        ));
});

it('records metadata for successful mysql logical backups', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-execute-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql execute path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', fakeMysqlScript(<<<'SH'
#!/bin/sh
set -e
for arg in "$@"; do
  case "$arg" in
    --result-file=*)
      file="${arg#--result-file=}"
      printf 'dump content' > "$file"
      ;;
  esac
done
printf 'backup complete'
SH
    ));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.output_prefix', 'mysql-export');
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new MysqlDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->command_output)->toContain('backup complete')
        ->and($run->artifact_path)->toBe($outputDir.'/mysql-export-'.$run->getKey().'.sql')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'mysql',
            'database' => ':memory:',
        ]);
});

it('captures pitr baseline and binlog chain metadata during planning', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-pitr-meta-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql PITR metadata path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $baseline = $outputDir.'/mysql-export-100.sql';
    file_put_contents($baseline, 'baseline');

    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.output_prefix', 'mysql-export');
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', [
        '/var/lib/mysql/binlog.000111',
        '/var/lib/mysql/binlog.000112',
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $baseline,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
    ]);

    $driver = new MysqlDriver;
    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => '2026-03-24 11:00:00',
    ]);

    $plannedMetadata = plannedMysqlMetadata($driver, $run);

    expect($plannedMetadata['pitr_base_target'])->toBe($baseline)
        ->and($plannedMetadata['metadata']['binlog_files'])->toBe([
            '/var/lib/mysql/binlog.000111',
            '/var/lib/mysql/binlog.000112',
        ]);
});

it('does not persist mysql pitr baseline artifacts into non-existent command run columns', function (): void {
    $driver = new MysqlDriver;
    $method = new ReflectionMethod($driver, 'persistedPlannedMetadata');

    $persisted = $method->invoke($driver, [
        'restore_target' => '2026-03-24 11:00:00',
        'restore_target_snapshot' => ['path' => '/tmp/snapshot.sql'],
        'pitr_base_target' => '/tmp/mysql-baseline.sql',
        'metadata' => [
            'driver' => 'mysql',
        ],
    ]);

    expect($persisted)->toMatchArray([
        'restore_target' => '2026-03-24 11:00:00',
        'metadata' => [
            'driver' => 'mysql',
        ],
    ])
        ->and($persisted)->not->toHaveKey('restore_target_snapshot')
        ->and($persisted)->not->toHaveKey('pitr_base_target');
});

it('redacts mysql command lines before persisting and logging them', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-redact-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql redaction path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', fakeMysqlScript(<<<'SH'
#!/bin/sh
set -e
for arg in "$@"; do
  case "$arg" in
    --result-file=*)
      file="${arg#--result-file=}"
      printf 'dump content' > "$file"
      ;;
  esac
done
printf 'backup complete'
SH
    ));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.output_prefix', 'mysql-export');
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');
    config()->set('checkpoint.drivers.mysql.extra_args.backup', [
        'mysql://app:super-secret@db.internal/app?password=query-secret',
        '--password',
        'top-secret',
        '--token=abc123',
    ]);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new MysqlDriver)->execute($run);

    $run->refresh();

    expect($run->command_line)->toContain('mysql://app:[REDACTED]@db.internal/app')
        ->and($run->command_line)->toContain('?password=[REDACTED]')
        ->and($run->command_line)->toContain("'--password' '[REDACTED]'")
        ->and($run->command_line)->toContain('--token=[REDACTED]')
        ->and($run->command_line)->not->toContain('super-secret')
        ->and($run->command_line)->not->toContain('query-secret')
        ->and($run->command_line)->not->toContain('top-secret')
        ->and($run->command_line)->not->toContain('abc123');
});

function buildMysqlProcess(MysqlDriver $driver, CommandRun $run, array $plannedMetadata = []): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run, $plannedMetadata);

    return $process;
}

/**
 * @return array<string, mixed>
 */
function plannedMysqlMetadata(MysqlDriver $driver, CommandRun $run): array
{
    $method = new ReflectionMethod($driver, 'plannedMetadata');

    /** @var array<string, mixed> $metadata */
    $metadata = $method->invoke($driver, $run);

    return $metadata;
}

function fakeMysqlScript(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'mysql-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql test script.');
    }

    file_put_contents($path, $contents."\n");
    chmod($path, 0755);

    return $path;
}
