<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;
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

it('records post-restore verification contract for mysql logical restores', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-restore-audit-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql restore audit path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.sql';
    file_put_contents($target, 'restore source');

    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    config()->set('checkpoint.drivers.mysql.mysql_binary', fakeMysqlScript("#!/bin/sh\nprintf 'restore complete'\n"));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.drivers.mysql.file_extension', 'sql');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new MysqlDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->metadata)->toMatchArray([
            'driver' => 'mysql',
            'database' => ':memory:',
            'restore_mode' => 'logical',
            'restored_via' => 'mysql',
        ]);

    expect($run->metadata['restore_audit'] ?? null)->toMatchArray([
        'environment' => (string) config('app.env'),
        'database' => ':memory:',
        'target' => $target,
        'confirmation_required' => true,
        'confirmation_satisfied_via' => 'token',
        'verified_backup_required' => false,
        'verified_signal_run_id' => null,
        'verified_signal_operation' => null,
        'verified_signal_backup_label' => null,
        'verified_signal_artifact_path' => null,
        'verified_signal_last_known_good_at' => null,
        'pitr_base_target' => null,
        'pitr_binlog_files' => [],
    ]);

    $postVerification = $run->metadata['restore_audit']['post_restore_verification'] ?? null;

    expect($postVerification)->toBeArray()
        ->and($postVerification)->toMatchArray([
            'contract_version' => 1,
            'command_run_id' => (int) $run->getKey(),
            'operation' => 'logical_restore_file',
            'aggregate_result' => 'pass',
            'checks_performed' => [
                'restore_audit_recorded',
                'restore_target_recorded',
                'command_exit_code_zero',
                'verified_backup_signal_linkage',
            ],
        ])
        ->and($postVerification['generated_at'] ?? null)->toBeString()
        ->and($postVerification['checks'])->toBeArray()
        ->and($postVerification['checks'])->toHaveCount(4)
        ->and($postVerification['checks'][0])->toHaveKeys(['name', 'passed', 'status', 'description', 'observed']);
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

it('plans replication_sync metadata with explicit default safety gates', function (): void {
    $run = CommandRun::factory()->make([
        'id' => 8801,
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:mysql-source","destination":"mysql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'mysql-source', 'redacted' => 'profile:mysql-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://root:secret@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $metadata = plannedMysqlMetadata(new MysqlDriver, $run);

    expect($metadata['metadata']['replication']['engine'] ?? null)->toBe('mysql')
        ->and($metadata['metadata']['replication']['dry_run_requested'] ?? null)->toBeTrue()
        ->and($metadata['metadata']['replication']['apply_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['force_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['overwrite_destination'] ?? null)->toBeFalse()
        ->and((string) ($metadata['metadata']['replication']['artifact_path'] ?? ''))->toContain('checkpoint-mysql-replication-8801.sql');
});

it('rejects replication_sync on mysql driver when replication engine is not mysql', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"pgsql://[REDACTED]","destination":"pgsql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://[REDACTED]'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://[REDACTED]'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    expect(fn (): array => plannedMysqlMetadata(new MysqlDriver, $run))
        ->toThrow(ConfigurationException::class, 'Unsupported replication engine [pgsql] for mysql driver. mysql driver supports mysql -> mysql only.');
});

it('runs replication_sync as dry-run-only by default and records conservative sanity metadata', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-repl-dry-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql replication path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $tempDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-repl-temp-');

    if ($tempDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql replication temp path.');
    }

    unlink($tempDir);
    mkdir($tempDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', fakeMysqlScript(<<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --result-file=*)
      target="${arg#--result-file=}"
      printf 'mysql replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH
    ));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.temp_dir', $tempDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:mysql-local","destination":"profile:mysql-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    (new MysqlDriver)->execute($run);
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->command_output)->toContain('[replication_sync:dry_run]')
        ->and($run->metadata['replication']['result'] ?? null)->toBe('dry_run_only')
        ->and($run->metadata['replication']['sanity']['method'] ?? null)->toBe('artifact_hash')
        ->and($run->metadata['replication']['sanity']['fallback_reason'] ?? null)->toBe('apply_not_requested_or_dry_run_enforced')
        ->and($run->metadata['replication']['destination']['redacted'] ?? null)->toBe('profile:mysql-local');
});

it('adds structured failure analysis and debug suggestions for mysql replication dry-run failures', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-repl-fail-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql replication path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', fakeMysqlScript(<<<'SH'
#!/bin/sh
echo 'ERROR 1045 (28000): Access denied for user "repl"@"10.0.0.5" (using password: YES)'
exit 1
SH
    ));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:mysql-local","destination":"profile:mysql-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    (new MysqlDriver)->execute($run);
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->command_output)->toContain('[replication_sync:debug]')
        ->and($run->command_output)->toContain('category: auth_credential_failure')
        ->and($run->metadata['replication']['failure_analysis']['diagnostics']['destination'] ?? null)->toBe('profile:mysql-local')
        ->and($run->metadata['replication']['failure_analysis']['category'] ?? null)->toBe('auth_credential_failure')
        ->and($run->metadata['replication']['failure_analysis']['immediate_fix'] ?? null)->toBeString()
        ->and($run->metadata['replication']['failure_context']['suggestions'][0] ?? null)->toBe($run->metadata['replication']['failure_analysis']['immediate_fix'] ?? null);
});

it('fails replication_sync when endpoints indicate remote or cross-host semantics', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:mysql-source","destination":"mysql://[REDACTED]","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'mysql-source', 'redacted' => 'profile:mysql-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://[REDACTED]@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    expect(function () use ($run): void {
        (new MysqlDriver)->execute($run);
    })
        ->toThrow(ConfigurationException::class, 'mysql replication execution currently supports only local/configured endpoint semantics.');
});

it('blocks mysql replication apply when governance preflight disallows execution-time apply', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-repl-governance-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql replication governance path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    $tempDir = tempnam(sys_get_temp_dir(), 'checkpoint-mysql-repl-governance-temp-');

    if ($tempDir === false) {
        throw new RuntimeException('Unable to allocate a temporary mysql replication governance temp path.');
    }

    unlink($tempDir);
    mkdir($tempDir, 0755, true);

    config()->set('checkpoint.drivers.mysql.dump_binary', fakeMysqlScript(<<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --result-file=*)
      target="${arg#--result-file=}"
      printf 'mysql replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH
    ));
    config()->set('checkpoint.drivers.mysql.output_dir', $outputDir);
    config()->set('checkpoint.temp_dir', $tempDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:mysql-local","destination":"profile:mysql-local","dry_run":false,"apply":true,"force_overwrite":true}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'mysql-local', 'redacted' => 'profile:mysql-local'],
                'queue_only' => true,
                'dry_run_requested' => false,
                'apply_requested' => true,
                'overwrite_destination' => true,
                'governance_preflight' => [
                    'allowed' => false,
                    'blocked_reasons' => ['outside_change_window'],
                ],
            ],
        ],
    ]);

    expect(function () use ($run): void {
        (new MysqlDriver)->execute($run);
    })
        ->toThrow(ConfigurationException::class, 'Replication apply is blocked by governance preflight at execution time: outside_change_window.');
});

it('maps checksum mismatch signatures in replication failure analysis', function (): void {
    $analysis = resolve(ReplicationFailureSuggestionMapper::class)->map(
        'apply_import',
        'replication sanity check failed: checksum mismatch for staged artifact',
        ['destination' => 'mysql://root:secret@db.internal'],
    );

    expect($analysis['category'])->toBe('checksum_sanity_verification_mismatch')
        ->and($analysis['diagnostics']['destination'] ?? null)->toBe('mysql://[REDACTED]@db.internal');
});

/**
 * @param  array<string, mixed>  $plannedMetadata
 */
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
