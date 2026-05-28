<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('builds restore commands from the latest logical export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $older = $outputDir.'/logical-export-10';
    $newer = $outputDir.'/logical-export-11';

    File::makeDirectory($older, 0755, true);
    File::makeDirectory($newer, 0755, true);
    touch($older, time() - 60);
    touch($newer, time());

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $olderTracked = $outputDir.'/logical-export-10';
    $newerTracked = $outputDir.'/logical-export-11';
    $filesystemNewest = $outputDir.'/logical-export-99';

    File::makeDirectory($olderTracked, 0755, true);
    File::makeDirectory($newerTracked, 0755, true);
    File::makeDirectory($filesystemNewest, 0755, true);
    touch($olderTracked, time() - 120);
    touch($newerTracked, time() - 60);
    touch($filesystemNewest, time());

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $validTracked = $outputDir.'/logical-export-10';
    $staleTracked = $outputDir.'/logical-export-11';

    File::makeDirectory($validTracked, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $safeExport = $outputDir.'/logical-export-10';
    $outsideExport = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-external-dir-');

    if ($outsideExport === false) {
        throw new RuntimeException('Unable to allocate an external export path.');
    }

    File::delete($outsideExport);
    File::makeDirectory($outsideExport, 0755, true);
    File::makeDirectory($safeExport, 0755, true);

    $linkedExport = $outputDir.'/logical-export-11';
    File::link($outsideExport, $linkedExport);
    touch($safeExport, time() - 60);
    touch($outsideExport, time());

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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
            'Target [%s] must be inside the configured output directory.',
            $linkedExport,
        ));
});

it('resolves relative restore arguments within the configured export directory', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-file-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.archive';
    touch($target);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);
    touch(File::dirname($outputDir).'/outside.archive');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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
            'Target [%s] must be inside the configured output directory.',
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
    File::move($outsideTargetBase, $outsideTarget);

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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
            'Target [%s] must be inside the configured output directory.',
            $outsideTarget,
        ));
});

it('rejects restore commands when the validated restore file changes before execution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-race-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-file path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $target = $outputDir.'/nightly-2026-03-11.archive';
    File::put($target, 'original');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.file_extension', 'archive');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly-2026-03-11',
    ]);

    $plannedMetadata = $handler->plannedMetadata($run);

    File::delete($target);
    File::put($target, 'replacement');

    expect(fn (): Process => $handler->buildProcess($run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'Target [%s] changed after validation and must be selected again.',
            $target,
        ));
});

it('rejects restore commands when a validated directory export changes before execution', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-dir-race-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore-directory path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $target = $outputDir.'/logical-export-42';
    File::makeDirectory($target, 0755, true);
    File::put($target.'/toc.dat', 'original');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'logical-export');

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'logical-export-42',
    ]);

    $plannedMetadata = $handler->plannedMetadata($run);

    File::put($target.'/toc.dat', 'mutated');

    expect(fn (): Process => $handler->buildProcess($run, $plannedMetadata))
        ->toThrow(ConfigurationException::class, sprintf(
            'Target [%s] changed after validation and must be selected again.',
            $target,
        ));
});

it('includes connection parameters in pg_restore commands when configured', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-restore-conn-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore connection test path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);
    touch($outputDir.'/test-export-50.dump');

    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'custom');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);
    config()->set('checkpoint.drivers.postgres.output_prefix', 'test-export');
    config()->set('database.connections.pgsql.host', 'replica.internal');
    config()->set('database.connections.pgsql.port', 5434);

    $handler = resolvePostgresLogicalRestoreHandler();
    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
    ]);

    $process = $handler->buildProcess($run, []);

    expect($process->getCommandLine())->toContain('--host=replica.internal')
        ->and($process->getCommandLine())->toContain('--port=5434');
});

it('blocks logical restores when no verified backup signal is available', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-guard-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore guard path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);
    File::makeDirectory($outputDir.'/logical-export-12', 0755, true);

    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
    config()->set('checkpoint.drivers.postgres.format', 'directory');
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => resolvePostgresDriver()->execute(postgresContext($run), $run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

it('blocks logical_restore_latest when the newest export lacks a matching verified signal', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-pgrestore-guard-latest-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary restore guard path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $older = $outputDir.'/logical-export-11';
    $newer = $outputDir.'/logical-export-12';

    File::makeDirectory($older, 0755, true);
    File::makeDirectory($newer, 0755, true);
    touch($older, time() - 60);
    touch($newer, time());

    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('database.connections.pgsql.dump.dump_binary_path', '');
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

    expect(fn (): mixed => resolvePostgresDriver()->execute(postgresContext($run), $run))
        ->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_latest] requires a verified backup signal before execution.');
});

it('records restore audit metadata for pgdump restore runs', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-restore-audit-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump restore audit path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_restore', "#!/bin/sh\nprintf 'restore complete'\n");
    File::chmod($binDir.'/pg_restore', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
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

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);

    $run->refresh();

    expect($context->isSuccessful())->toBeTrue()
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

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    config()->set('checkpoint.output.max_persisted_bytes', 48);
    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_restore', "#!/bin/sh\nprintf '%s' \"$(printf 'R%.0s' \$(seq 1 96))\"\n");
    File::chmod($binDir.'/pg_restore', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
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

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);

    $run->refresh();

    expect($context->isSuccessful())->toBeTrue()
        ->and(Str::length((string) $run->command_output))->toBeLessThanOrEqual(48)
        ->and($run->command_output)->toContain('...[truncated ')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'postgres',
            'restored_via' => 'pg_restore',
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 96,
                'persisted_bytes' => Str::length((string) $run->command_output),
                'max_persisted_bytes' => 48,
            ],
        ]);
});
