<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Date;

it('filters command runs through the status scopes', function (): void {
    $pendingRun = CommandRun::factory()->pending()->create();
    $runningRun = CommandRun::factory()->running()->create();
    $succeededRun = CommandRun::factory()->succeeded()->create();
    $failedRun = CommandRun::factory()->failed()->create();
    $cancelledRun = CommandRun::factory()->cancelled()->create();
    $terminalIds = CommandRun::query()->terminal()->pluck('id')->all();
    $expectedTerminalIds = [$succeededRun->id, $failedRun->id, $cancelledRun->id];
    sort($terminalIds);
    sort($expectedTerminalIds);

    expect(CommandRun::query()->pending()->pluck('id')->all())->toBe([$pendingRun->id])
        ->and(CommandRun::query()->running()->pluck('id')->all())->toBe([$runningRun->id])
        ->and(CommandRun::query()->succeeded()->pluck('id')->all())->toBe([$succeededRun->id])
        ->and(CommandRun::query()->failed()->pluck('id')->all())->toBe([$failedRun->id])
        ->and($terminalIds)->toBe($expectedTerminalIds);
});

it('filters verified and last-known-good backup runs through metadata scopes', function (): void {
    $verifiedRun = CommandRun::factory()->succeeded()->create([
        'verification_state' => 'verified',
        'last_known_good_at' => Date::now()->subMinute(),
    ]);
    $nonVerifiedRun = CommandRun::factory()->succeeded()->create([
        'verification_state' => 'failed',
        'last_known_good_at' => null,
    ]);
    $olderGoodRun = CommandRun::factory()->succeeded()->create([
        'verification_state' => 'verified',
        'last_known_good_at' => Date::now()->subMinutes(5),
    ]);

    expect(CommandRun::query()->verified()->pluck('id')->all())->toBe([$verifiedRun->id, $olderGoodRun->id])
        ->and(CommandRun::query()->lastKnownGood()->pluck('id')->all())->toBe([$verifiedRun->id, $olderGoodRun->id])
        ->and(CommandRun::query()->lastKnownGood()->pluck('id')->all())->not->toContain($nonVerifiedRun->id);
});

it('updates status metadata through markAs helper methods', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->pending()->create();

    $run->markAsRunning();
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Running)
        ->and($run->started_at?->toDateTimeString())->toBe('2026-03-11 12:00:00');

    Date::setTestNow('2026-03-11 12:05:00');
    $run->recordMetadata([
        'backup_size_bytes' => 600,
    ]);

    $run->markAsSucceeded(0, 'done');
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->command_output)->toBe('done')
        ->and($run->duration_seconds)->toBe(300)
        ->and($run->throughput_bytes_per_second)->toBe(2)
        ->and($run->finished_at?->toDateTimeString())->toBe('2026-03-11 12:05:00');

    Date::setTestNow('2026-03-11 12:10:00');

    $run->markAsFailed(2, 'broken');
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->command_output)->toBe('done')
        ->and($run->finished_at?->toDateTimeString())->toBe('2026-03-11 12:05:00');

    Date::setTestNow();
});

it('claims pending runs atomically and does not reopen terminal runs', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->pending()->create();

    $run->markAsRunning();
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Running);

    $staleCopy = CommandRun::query()->findOrFail($run->getKey());

    $run->markAsFailed(1, 'timed out');
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->command_output)->toBe('timed out');

    $staleCopy->markAsSucceeded(0, 'late success');
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->command_output)->toBe('timed out');

    Date::setTestNow();
});

it('selects prunable records using the configured retention windows', function (): void {
    config()->set('checkpoint.schedule.prune_keep_days', 30);
    config()->set('checkpoint.schedule.prune_keep_failed_days', 365);

    $prunableSucceeded = CommandRun::factory()->succeeded()->create([
        'created_at' => Date::now()->subDays(45),
        'updated_at' => Date::now()->subDays(45),
    ]);

    $retainedFailed = CommandRun::factory()->failed()->create([
        'created_at' => Date::now()->subDays(100),
        'updated_at' => Date::now()->subDays(100),
    ]);

    $prunableFailed = CommandRun::factory()->failed()->create([
        'created_at' => Date::now()->subDays(400),
        'updated_at' => Date::now()->subDays(400),
    ]);

    $prunableIds = (new CommandRun)->prunable()->pluck('id')->all();
    $expectedPrunableIds = [$prunableSucceeded->id, $prunableFailed->id];
    sort($prunableIds);
    sort($expectedPrunableIds);

    expect($prunableIds)->toBe($expectedPrunableIds);
    expect($prunableIds)->not->toContain($retainedFailed->id);
});

it('exposes a polymorphic requester relation', function (): void {
    $run = CommandRun::factory()->make([
        'requested_by_type' => User::class,
        'requested_by_id' => 123,
    ]);

    expect($run->requestedBy())->toBeInstanceOf(MorphTo::class);
});
