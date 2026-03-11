<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Event;

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
