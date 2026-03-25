<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

it('executes argv tokens without shell interpretation', function (): void {
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'printf %s:%s:%s $HOME *.sql {db}');

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->command_output)->toBe('$HOME:*.sql::memory:');
});

it('substitutes configured placeholders into the process argv', function (): void {
    config()->set('checkpoint.drivers.shell.commands.logical_restore_file', 'printf %s:%s:%s {db} {file} {output}');
    config()->set('checkpoint.drivers.shell.pre_restore_snapshot', false);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'archive.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->command_output)->toBe(':memory::archive.sql:/tmp/checkpoint-tests/backup-'.$run->getKey().'.sql');
});

it('creates a pre-restore snapshot before restore operations', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'printf snapshot');
    config()->set('checkpoint.drivers.shell.commands.logical_restore_file', 'printf restore');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'archive.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();
    $snapshotRun = CommandRun::query()
        ->where('operation', 'logical_backup')
        ->whereKeyNot($run->getKey())
        ->sole();

    expect(CommandRun::query()->count())->toBe(2)
        ->and($snapshotRun->status)->toBe(CommandRunStatus::Succeeded)
        ->and($snapshotRun->command_output)->toBe('snapshot')
        ->and($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->command_output)->toBe('restore');

    Event::assertDispatchedTimes(BackupStarted::class, 2);
    Event::assertDispatchedTimes(BackupCompleted::class, 2);
    Event::assertNotDispatched(BackupFailed::class);
});

it('aborts restore operations when the pre-restore snapshot fails', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'php -r exit(1);');
    config()->set('checkpoint.drivers.shell.commands.logical_restore_file', 'printf restore');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'archive.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();
    $snapshotRun = CommandRun::query()
        ->where('operation', 'logical_backup')
        ->whereKeyNot($run->getKey())
        ->sole();

    expect(CommandRun::query()->count())->toBe(2)
        ->and($snapshotRun->status)->toBe(CommandRunStatus::Failed)
        ->and($snapshotRun->exit_code)->toBe(1)
        ->and($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(-1)
        ->and($run->command_output)->toBe('messages.errors.pre_restore_failed');

    Event::assertDispatchedTimes(BackupStarted::class, 1);
    Event::assertDispatchedTimes(BackupCompleted::class, 0);
    Event::assertDispatchedTimes(BackupFailed::class, 2);
});

it('blocks restore execution when confirmation is missing', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', null);
    config()->set('checkpoint.drivers.shell.pre_restore_snapshot', false);
    config()->set('checkpoint.drivers.shell.commands.logical_restore_file', 'printf restore');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'archive.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new ShellCommandDriver)->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore confirmation is required.');
});

it('records restore audit metadata for shell restore runs', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    config()->set('checkpoint.drivers.shell.pre_restore_snapshot', false);
    config()->set('checkpoint.drivers.shell.commands.logical_restore_file', 'printf restore');

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'archive.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->metadata)->toMatchArray([
            'driver' => 'shell',
            'restore_audit' => [
                'environment' => (string) config('app.env'),
                'database' => ':memory:',
                'target' => 'archive.sql',
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

it('redacts shell command lines before persisting and logging them', function (): void {
    config()->set(
        'checkpoint.drivers.shell.commands.logical_backup',
        'printf ok postgresql://app:super-secret@db.internal/app?password=query-secret --password top-secret --token=abc123'
    );

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

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

it('truncates persisted shell command output and records capture metadata', function (): void {
    config()->set('checkpoint.output.max_persisted_bytes', 40);
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'printf '.str_repeat('A', 30).str_repeat('B', 30));

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and(strlen((string) $run->command_output))->toBeLessThanOrEqual(40)
        ->and($run->command_output)->toContain('...[truncated ')
        ->and($run->metadata)->toMatchArray([
            'driver' => 'shell',
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 60,
                'persisted_bytes' => strlen((string) $run->command_output),
                'max_persisted_bytes' => 40,
            ],
        ]);
});

it('externalizes shell command output to filesystem storage with an inline preview', function (): void {
    Storage::fake('local');
    Event::fake([BackupCompleted::class]);

    config()->set('checkpoint.output.max_persisted_bytes', 64);
    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.filesystem.disk', 'local');
    config()->set('checkpoint.output.filesystem.path_prefix', 'checkpoint/test-output');
    config()->set('checkpoint.output.filesystem.inline_bytes', 24);
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'printf '.str_repeat('A', 120));

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new ShellCommandDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and(strlen((string) $run->command_output))->toBeLessThanOrEqual(24)
        ->and($run->metadata)->toMatchArray([
            'output_capture' => [
                'truncated' => true,
                'original_bytes' => 120,
                'persisted_bytes' => 64,
                'max_persisted_bytes' => 64,
            ],
            'output_storage' => [
                'driver' => 'filesystem',
                'externalized' => true,
                'disk' => 'local',
                'path' => 'checkpoint/test-output/command-run-'.$run->getKey().'.log',
                'stored_bytes' => 120,
                'inline_bytes' => strlen((string) $run->command_output),
            ],
        ])
        ->and($run->resolvedCommandOutput())->toBe(str_repeat('A', 120));

    Storage::disk('local')->assertExists('checkpoint/test-output/command-run-'.$run->getKey().'.log');
    Event::assertDispatched(BackupCompleted::class, fn (BackupCompleted $event): bool => $event->output === (string) $run->command_output);
});
