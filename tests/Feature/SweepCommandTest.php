<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('marks timed-out running runs as failed and leaves recent runs untouched', function (): void {
    Event::fake([BackupFailed::class]);

    Log::shouldReceive('channel->error')
        ->once()
        ->with('Sweep marked command run as failed', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_backup'
                && $context['driver'] === 'mysql'
                && $context['timeout_seconds'] === 300
        ));

    config()->set('checkpoint.queue.timeout', 300);

    $timedOutRun = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Running,
        'attempts' => 0,
        'started_at' => Date::now()->subMinutes(10),
    ]);

    $healthyRun = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Running,
        'attempts' => 0,
        'started_at' => Date::now()->subMinutes(2),
    ]);

    checkpoint_artisan('checkpoint:sweep')
        ->expectsOutput('Marked run #1 as failed (timed out after 300 seconds).')
        ->assertSuccessful();

    $timedOutRun->refresh();
    $healthyRun->refresh();

    expect($timedOutRun->status)->toBe(CommandRunStatus::Failed)
        ->and($timedOutRun->command_output)->toBe('Timed out by health check')
        ->and($timedOutRun->exit_code)->toBe(-1)
        ->and($healthyRun->status)->toBe(CommandRunStatus::Running);

    Event::assertDispatched(fn (BackupFailed $event): bool => $event->run->is($timedOutRun)
        && $event->output === 'Timed out by health check'
        && $event->exitCode === -1
        && $event->version === 1);
});

it('keeps long-running runs alive when heartbeats are fresh', function (): void {
    Event::fake([BackupFailed::class]);

    config()->set('checkpoint.queue.timeout', 300);
    config()->set('checkpoint.queue.heartbeat_grace_seconds', 60);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Running,
        'attempts' => 0,
        'started_at' => Date::now()->subMinutes(20),
        'heartbeat_at' => Date::now()->subMinutes(2),
    ]);

    checkpoint_artisan('checkpoint:sweep')->assertSuccessful();

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Running);
    Event::assertNotDispatched(BackupFailed::class);
});
