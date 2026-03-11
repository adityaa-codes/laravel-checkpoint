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

    checkpoint_artisan('db-ops:enqueue pgbackrest_info')
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

    checkpoint_artisan('db-ops:enqueue')
        ->expectsChoice(
            'Which operation would you like to queue?',
            'logical_restore_file',
            [
                'logical_backup',
                'logical_restore_latest',
                'logical_restore_file',
                'pitr_restore',
                'backup_drill',
                'pgbackrest_check',
                'pgbackrest_info',
            ],
        )
        ->expectsQuestion('Enter the argument for the selected operation', 'nightly.sql')
        ->expectsOutput('Queued Logical Restore (Specific File) run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();

    expect($run->operation)->toBe('logical_restore_file')
        ->and($run->argument_text)->toBe('nightly.sql');
});
