<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('marks timed-out running runs as failed and leaves recent runs untouched', function (): void {
    Event::fake([BackupFailed::class]);

    Log::shouldReceive('channel->error')
        ->once()
        ->with('Health check marked command run as failed', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_backup'
                && $context['timeout_seconds'] === 300
        ));

    config()->set('checkpoint.queue.timeout', 300);

    $timedOutRun = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Running,
        'attempts' => 0,
        'started_at' => Carbon::now()->subMinutes(10),
    ]);

    $healthyRun = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Running,
        'attempts' => 0,
        'started_at' => Carbon::now()->subMinutes(2),
    ]);

    checkpoint_artisan('db-ops:health-check')
        ->expectsOutput('Marked run #1 as failed (timed out after 300 seconds).')
        ->assertSuccessful();

    $timedOutRun->refresh();
    $healthyRun->refresh();

    expect($timedOutRun->status)->toBe(CommandRunStatus::Failed)
        ->and($timedOutRun->command_output)->toBe('Timed out by health check')
        ->and($timedOutRun->exit_code)->toBe(-1)
        ->and($healthyRun->status)->toBe(CommandRunStatus::Running);

    Event::assertDispatched(BackupFailed::class, fn (BackupFailed $event): bool => $event->run->is($timedOutRun)
        && $event->output === 'Timed out by health check'
        && $event->exitCode === -1);
});
