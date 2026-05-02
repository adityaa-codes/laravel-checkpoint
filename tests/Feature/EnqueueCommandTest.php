<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('queues the provided operation from the generic command', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('checkpoint:enqueue pgbackrest_info')
        ->expectsOutput('Queued pgBackRest Info run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();

    expect($run->operation)->toBe('pgbackrest_info')
        ->and($run->argument_text)->toBeNull();

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($run));
    Event::assertDispatched(fn (BackupQueued $event): bool => $event->run->is($run));
});

it('prompts for the operation and argument when needed', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('checkpoint:enqueue')
        ->expectsChoice(
            'Which operation would you like to queue?',
            'logical_restore_file',
            [
                'logical_backup',
                'logical_restore_latest',
                'logical_restore_file',
                'backup_drill',
                'pgbackrest_backup_full',
                'pgbackrest_backup_diff',
                'pgbackrest_backup_incr',
                'pgbackrest_restore',
                'pgbackrest_verify',
                'pgbackrest_check',
                'pgbackrest_info',
                'pitr_restore',
                'replication_sync',
            ],
        )
        ->expectsQuestion('Enter the argument for the selected operation', 'nightly.sql')
        ->expectsOutput('Queued Logical Restore (Specific File) run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();

    expect($run->operation)->toBe('logical_restore_file')
        ->and($run->argument_text)->toBe('nightly.sql');
});

it('fails in non-interactive mode when operation is missing', function (): void {
    Bus::fake();

    checkpoint_artisan('checkpoint:enqueue', ['--no-interaction' => true])
        ->expectsOutput('Operation is required in non-interactive mode. Pass it as checkpoint:enqueue <operation>.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('fails in non-interactive mode when required operation argument is missing', function (): void {
    Bus::fake();

    checkpoint_artisan('checkpoint:enqueue', [
        'operation' => 'logical_restore_file',
        '--no-interaction' => true,
    ])
        ->expectsOutput('Operation [logical_restore_file] requires --argument in non-interactive mode.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});
