<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationSyncHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

it('builds directory format pg_dump commands with parallel jobs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary output path.');
    }

    unlink($outputDir);

    config()->set('checkpoint.drivers.postgres.dump_binary', 'pg_dump');
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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 4);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');
    config()->set('checkpoint.drivers.postgres.clean', true);

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = $handler->buildProcess($run, []);

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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 4);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');

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

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = $handler->buildProcess($run, []);

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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 4);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');

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

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = $handler->buildProcess($run, []);

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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 4);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    expect(fn (): Process => $handler->buildProcess($run, []))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured postgres output directory.',
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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.jobs', 1);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');
    config()->set('checkpoint.drivers.postgres.extra_args.restore', ['--if-exists']);

    touch($outputDir.'/nightly-2026-03-11.archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $process = $handler->buildProcess($run, []);

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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => $target,
    ]);

    $process = $handler->buildProcess($run, []);

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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => '../outside',
    ]);

    expect(fn (): Process => $handler->buildProcess($run, []))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured postgres output directory.',
            $outputDir.'/../outside.archive',
        ));
});

it('rejects absolute restore paths outside the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-outside-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    $outsideTargetBase = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-outside-artifact-');

    if ($outsideTargetBase === false) {
        throw new RuntimeException('Unable to allocate an external restore artifact.');
    }

    $outsideTarget = $outsideTargetBase.'.archive';
    rename($outsideTargetBase, $outsideTarget);

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => $outsideTarget,
    ]);

    expect(fn (): Process => $handler->buildProcess($run, []))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical_restore_file target [%s] must be inside the configured postgres output directory.',
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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $plannedMetadata = $handler->plannedMetadata($run);

    unlink($target);
    file_put_contents($target, 'replacement');

    expect(fn (): Process => $handler->buildProcess($run, $plannedMetadata))
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

    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'logical-export-42',
    ]);

    $plannedMetadata = $handler->plannedMetadata($run);

    file_put_contents($target.'/toc.dat', 'mutated');

    expect(fn (): Process => $handler->buildProcess($run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'logical restore target [%s] changed after validation and must be selected again.',
            $target,
        ));
});

it('rejects unsupported Postgres operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'unknown_op',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn () => resolvePostgresDriver()->execute($run))
        ->toThrow(ConfigurationException::class, 'Unsupported Postgres operation [unknown_op].');
});

it('plans replication_sync metadata with explicit default safety gates', function (): void {
    $run = CommandRun::factory()->make([
        'id' => 7001,
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-source","destination":"pgsql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-source', 'redacted' => 'profile:pg-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://replicator:secret@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $handler = resolvePostgresReplicationSyncHandler();
    $metadata = $handler->plannedMetadata($run);

    expect($metadata['metadata']['replication']['engine'] ?? null)->toBe('pgsql')
        ->and($metadata['metadata']['replication']['dry_run_requested'] ?? null)->toBeTrue()
        ->and($metadata['metadata']['replication']['apply_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['force_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['overwrite_destination'] ?? null)->toBeFalse()
        ->and((string) ($metadata['metadata']['replication']['artifact_path'] ?? ''))->toContain('checkpoint-replication-7001.dump');
});

it('rejects replication_sync on Postgres driver when replication engine is not pgsql', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"mysql://[REDACTED]","destination":"mysql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://[REDACTED]'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://[REDACTED]'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $handler = resolvePostgresReplicationSyncHandler();

    expect(fn (): array => $handler->plannedMetadata($run))
        ->toThrow(ConfigurationException::class, 'Unsupported replication engine [mysql] for Postgres driver. Postgres driver supports pgsql -> pgsql only.');
});

it('runs replication_sync as dry-run-only by default and records conservative sanity metadata', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-dry-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript(<<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --file=*)
      target="${arg#--file=}"
      printf 'replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH));
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    resolvePostgresDriver()->execute($run);
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->command_output)->toContain('[replication_sync:dry_run]')
        ->and($run->metadata['replication']['result'] ?? null)->toBe('dry_run_only')
        ->and($run->metadata['replication']['sanity']['method'] ?? null)->toBe('artifact_hash')
        ->and($run->metadata['replication']['sanity']['fallback_reason'] ?? null)->toBe('apply_not_requested_or_dry_run_enforced')
        ->and($run->metadata['replication']['destination']['redacted'] ?? null)->toBe('profile:pg-local');
});

it('adds structured failure analysis and debug suggestions for pg replication dry-run failures', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-fail-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript(<<<'SH'
#!/bin/sh
echo 'could not translate host name "db.invalid" to address: Name or service not known'
exit 1
SH
    ));
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    resolvePostgresDriver()->execute($run);
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->command_output)->toContain('[replication_sync:debug]')
        ->and($run->command_output)->toContain('category: dns_network_connection_refused')
        ->and($run->command_output)->not->toContain('secret')
        ->and($run->metadata['replication']['failure_analysis']['category'] ?? null)->toBe('dns_network_connection_refused')
        ->and($run->metadata['replication']['failure_context']['suggestions'][0] ?? null)->toBe($run->metadata['replication']['failure_analysis']['immediate_fix'] ?? null);
});

it('fails replication_sync when endpoints indicate remote or cross-host semantics', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-source","destination":"pgsql://[REDACTED]","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-source', 'redacted' => 'profile:pg-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://[REDACTED]@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    expect(function () use ($run): void {
        resolvePostgresDriver()->execute($run);
    })
        ->toThrow(ConfigurationException::class, 'postgres replication execution currently supports only local/configured endpoint semantics.');
});

it('blocks Postgres replication apply when governance preflight disallows execution-time apply', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-governance-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication governance path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript(<<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --file=*)
      target="${arg#--file=}"
      printf 'replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH
    ));
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":false,"apply":true,"force_overwrite":true}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => false,
                'apply_requested' => true,
                'overwrite_destination' => true,
                'governance_preflight' => [
                    'allowed' => false,
                    'blocked_reasons' => ['destination_not_allowlisted'],
                ],
            ],
        ],
    ]);

    expect(function () use ($run): void {
        resolvePostgresDriver()->execute($run);
    })
        ->toThrow(ConfigurationException::class, 'Replication apply is blocked by governance preflight at execution time: destination_not_allowlisted.');
});

it('maps invalid dsn parse signatures in replication failure analysis', function (): void {
    $analysis = resolve(ReplicationFailureSuggestionMapper::class)->map(
        'dry_run_export',
        'invalid DSN: failed to parse endpoint URL',
        ['source' => 'pgsql://user:secret@db.internal/source'],
    );

    expect($analysis['category'])->toBe('invalid_url_dsn_parse')
        ->and($analysis['diagnostics']['source'] ?? null)->toBe('pgsql://[REDACTED]@db.internal');
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

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript(<<<'SH'
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
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.jobs', 4);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    resolvePostgresDriver()->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->backup_type)->toBe('logical_export')
        ->and($run->artifact_path)->toBe($outputDir.'/logical-export-'.$run->getKey())
        ->and($run->backup_size_bytes)->toBe(7)
        ->and($run->verification_state)->toBe('not_applicable')
        ->and($run->last_known_good_at)->not->toBeNull()
        ->and($run->duration_seconds)->toBeInt()
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'format' => 'directory',
            'jobs' => 4,
        ]);

    expect($run->metadata['artifact_snapshot'] ?? null)->toBeArray()
        ->and($run->metadata['artifact_snapshot']['path'] ?? null)->toBe($outputDir.'/logical-export-'.$run->getKey())
        ->and($run->metadata['artifact_snapshot']['file_type'] ?? null)->toBe('directory');

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
    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => resolvePostgresDriver()->execute($run))
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
    config()->set('checkpoint.drivers.postgres.restore_binary', 'pg_restore');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

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

    expect(fn (): mixed => resolvePostgresDriver()->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

it('records restore audit metadata for pgdump restore runs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-restore-audit-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump restore audit path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    config()->set('checkpoint.drivers.postgres.restore_binary', fakePostgresScript("#!/bin/sh\nprintf 'restore complete'\n"));
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.jobs', 1);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    touch($outputDir.'/nightly-2026-03-11.archive');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    resolvePostgresDriver()->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'format' => 'custom',
            'jobs' => 1,
            'restored_via' => 'pg_restore',
        ]);

    expect($run->metadata['restore_audit'] ?? null)->toMatchArray([
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

it('records output capture metadata for truncated pgdump restore output', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-restore-truncate-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump restore truncation path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.output.max_persisted_bytes', 48);
    config()->set('checkpoint.drivers.postgres.restore_binary', fakePostgresScript("#!/bin/sh\nprintf '%s' \"$(printf 'R%.0s' $(seq 1 96))\"\n"));
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.jobs', 1);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    touch($outputDir.'/nightly-2026-03-11.archive');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    resolvePostgresDriver()->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and(strlen((string) $run->command_output))->toBeLessThanOrEqual(48)
        ->and($run->command_output)->toContain('...[truncated ')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'restored_via' => 'pg_restore',
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 96,
                'persisted_bytes' => strlen((string) $run->command_output),
                'max_persisted_bytes' => 48,
            ],
        ]);
});

it('redacts pg_dump command lines before persisting and logging them', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-redact-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump redaction path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript("#!/bin/sh\nprintf 'ok'\n"));
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

    resolvePostgresDriver()->execute($run);

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
    Log::shouldReceive('channel')->times(2)->andReturn($logger);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => resolvePostgresDriver()->execute($run))
        ->toThrow(RuntimeException::class, 'logger runtime failure');

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(-1);
});

it('truncates persisted pgdump output and records capture metadata', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-truncate-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump truncation path.');
    }

    unlink($outputDir);
    mkdir($outputDir, 0755, true);

    config()->set('checkpoint.output.max_persisted_bytes', 48);
    config()->set('checkpoint.drivers.postgres.dump_binary', fakePostgresScript("#!/bin/sh\nprintf '%s' \"$(printf 'X%.0s' $(seq 1 80))\"\n"));
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    resolvePostgresDriver()->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and(strlen((string) $run->command_output))->toBeLessThanOrEqual(48)
        ->and($run->command_output)->toContain('...[truncated ')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 80,
                'persisted_bytes' => strlen((string) $run->command_output),
                'max_persisted_bytes' => 48,
            ],
        ]);
});

function resolvePostgresDriver(): PostgresDriver
{
    return app(PostgresDriver::class);
}

function resolvePostgresLogicalBackupHandler(): PostgresLogicalBackupHandler
{
    return app(PostgresLogicalBackupHandler::class);
}

function resolvePostgresLogicalRestoreHandler(): PostgresLogicalRestoreHandler
{
    return app(PostgresLogicalRestoreHandler::class);
}

function resolvePostgresReplicationSyncHandler(): PostgresReplicationSyncHandler
{
    return app(PostgresReplicationSyncHandler::class);
}

function fakePostgresScript(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'postgres-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump test script.');
    }

    file_put_contents($path, $contents."\n");
    chmod($path, 0755);

    return $path;
}
