<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

it('queues a logical backup from the artisan command', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('checkpoint:enqueue-backup')
        ->expectsOutput('Queued Logical Backup run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();

    expect($run->operation)->toBe('logical_backup')
        ->and($run->argument_text)->toBeNull();

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($run));
    Event::assertDispatched(fn (BackupQueued $event): bool => $event->run->is($run));
});

it('prints an error and exits with failure when enqueueing fails', function (): void {
    /** @var MockInterface&EnqueueCommandRunAction $action */
    $action = Mockery::mock(EnqueueCommandRunAction::class);
    $action->shouldReceive('execute')
        ->once()
        ->with('logical_backup')
        ->andThrow(new RuntimeException('Queue broker unavailable.'));

    app()->instance(EnqueueCommandRunAction::class, $action);

    checkpoint_artisan('checkpoint:enqueue-backup')
        ->expectsOutputToContain('Queue broker unavailable.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
});
