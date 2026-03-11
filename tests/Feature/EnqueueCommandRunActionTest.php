<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('creates a pending command run and dispatches processing after commit', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    $run = app(EnqueueCommandRunAction::class)->execute('logical_restore_file', ' nightly.sql ');

    expect($run->exists)->toBeTrue()
        ->and($run->status)->toBe(CommandRunStatus::Pending)
        ->and($run->argument_text)->toBe('nightly.sql')
        ->and($run->attempts)->toBe(0);

    /** @var CommandRun|null $storedRun */
    $storedRun = CommandRun::query()->find($run->getKey());

    expect($storedRun)->not->toBeNull();
    expect($storedRun?->argument_text)->toBe('nightly.sql');

    Bus::assertDispatched(ProcessCommandRunJob::class, fn (ProcessCommandRunJob $job): bool => $job->run->is($run)
        && $job->queue === 'db-ops'
        && $job->afterCommit === true);

    Event::assertDispatched(BackupQueued::class, fn (BackupQueued $event): bool => $event->run->is($run));
});

it('rejects invalid arguments without creating a run or dispatching a job', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    expect(fn () => app(EnqueueCommandRunAction::class)->execute('logical_restore_file'))
        ->toThrow(InvalidArgumentException::class);

    expect(CommandRun::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
    Event::assertNotDispatched(BackupQueued::class);
});
