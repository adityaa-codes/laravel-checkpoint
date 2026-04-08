<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

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

it('denormalizes hot operator fields from metadata payloads', function (): void {
    $run = CommandRun::factory()->pending()->create();

    $run->recordMetadata([
        'metadata' => [
            'driver' => 'pgbackrest',
            'restore_audit' => [
                'confirmation_satisfied_via' => 'token',
                'verified_signal_run_id' => 42,
                'post_restore_verification' => [
                    'aggregate_result' => 'pass',
                ],
                'verified_signal_operation' => 'pgbackrest_info',
            ],
        ],
    ]);

    $run->refresh();

    expect($run->driver_name)->toBe('pgbackrest')
        ->and($run->restore_confirmation_satisfied_via)->toBe('token')
        ->and($run->restore_verified_signal_run_id)->toBe(42)
        ->and($run->restore_post_verification_result)->toBe('pass')
        ->and($run->resolvedDriverName('shell'))->toBe('pgbackrest')
        ->and($run->restoreAuditSummary())->toBe([
            'confirmation_satisfied_via' => 'token',
            'verified_signal_run_id' => 42,
        ])
        ->and($run->restorePostVerificationSummary())->toBe([
            'aggregate_result' => 'pass',
        ]);
});

it('persists verification rows from verification metadata transitions', function (): void {
    $run = CommandRun::factory()->pending()->create([
        'operation' => 'pgbackrest_verify',
    ]);

    $run->recordMetadata([
        'verification_state' => 'verified',
        'verified_at' => Date::now(),
        'metadata' => [
            'driver' => 'pgbackrest',
            'summary' => ['ok' => true],
        ],
    ]);

    $verification = VerificationRun::query()->first();

    expect($verification)->not->toBeNull()
        ->and($verification?->verification_type)->toBe('pgbackrest_verify')
        ->and($verification?->status)->toBe('verified')
        ->and($run->fresh()?->verificationRuns()->count())->toBe(1);
});

it('clears denormalized operator fields when metadata no longer carries them', function (): void {
    $run = CommandRun::factory()->pending()->create([
        'driver_name' => 'pgbackrest',
        'restore_confirmation_satisfied_via' => 'token',
        'restore_verified_signal_run_id' => 42,
        'restore_post_verification_result' => 'pass',
        'metadata' => [
            'driver' => 'pgbackrest',
            'restore_audit' => [
                'confirmation_satisfied_via' => 'token',
                'verified_signal_run_id' => 42,
                'post_restore_verification' => [
                    'aggregate_result' => 'pass',
                ],
            ],
        ],
    ]);

    $run->recordMetadata([
        'metadata' => [
            'restore_audit' => [
                'environment' => 'testing',
            ],
        ],
    ]);

    $run->refresh();

    expect($run->driver_name)->toBeNull()
        ->and($run->restore_confirmation_satisfied_via)->toBeNull()
        ->and($run->restore_verified_signal_run_id)->toBeNull()
        ->and($run->restore_post_verification_result)->toBeNull()
        ->and($run->restoreAuditSummary())->toBe([
            'confirmation_satisfied_via' => null,
            'verified_signal_run_id' => null,
        ])
        ->and($run->restorePostVerificationSummary())->toBe([
            'aggregate_result' => null,
        ]);
});

it('claims pending runs atomically and does not reopen terminal runs', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->pending()->create();

    $run->markAsRunning();
    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Running);

    /** @var CommandRun $staleCopy */
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

it('claims orphaned pending runs once using the updated heartbeat', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->pending()->create([
        'created_at' => Date::now()->subMinutes(45),
        'updated_at' => Date::now()->subMinutes(30),
    ]);

    $threshold = Date::now()->subMinutes(10);
    $claimExpiresBefore = Date::now()->subMinute();

    expect($run->claimForOrphanRecovery($threshold, $claimExpiresBefore, Date::now()))->toBeTrue();

    $run->refresh();

    expect($run->updated_at?->toDateTimeString())->toBe('2026-03-11 11:30:00')
        ->and($run->orphan_recovery_claimed_at?->toDateTimeString())->toBe('2026-03-11 12:00:00')
        ->and($run->claimForOrphanRecovery($threshold, $claimExpiresBefore, Date::now()->addMinute()))->toBeFalse();

    $run->releaseOrphanRecoveryClaim(Date::now());
    $run->refresh();

    expect($run->orphan_recovery_claimed_at)->toBeNull();

    Date::setTestNow('2026-03-11 12:02:00');

    expect($run->claimForOrphanRecovery($threshold, Date::now()->subMinute(), Date::now()))->toBeTrue();

    Date::setTestNow();
});

it('allows only one pending execution claimant across stale copies', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->pending()->create([
        'orphan_recovery_claimed_at' => Date::now()->subMinute(),
    ]);
    /** @var CommandRun $staleCopy */
    $staleCopy = CommandRun::query()->findOrFail($run->getKey());

    expect($run->claimPendingExecution())->toBeTrue()
        ->and($staleCopy->claimPendingExecution())->toBeFalse();

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Running)
        ->and($run->started_at?->toDateTimeString())->toBe('2026-03-11 12:00:00')
        ->and($run->heartbeat_at?->toDateTimeString())->toBe('2026-03-11 12:00:00')
        ->and($run->orphan_recovery_claimed_at)->toBeNull();

    Date::setTestNow();
});

it('records heartbeats only when due for running runs', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    $run = CommandRun::factory()->running()->create([
        'started_at' => Date::now()->subMinutes(5),
        'heartbeat_at' => Date::now()->subSeconds(10),
    ]);

    expect($run->recordHeartbeatIfDue(Date::now(), 30, refresh: true))->toBeFalse()
        ->and($run->heartbeat_at?->toDateTimeString())->toBe('2026-03-11 11:59:50');

    Date::setTestNow('2026-03-11 12:00:25');

    expect($run->recordHeartbeatIfDue(Date::now(), 30, refresh: true))->toBeTrue()
        ->and($run->heartbeat_at?->toDateTimeString())->toBe('2026-03-11 12:00:25');

    Date::setTestNow();
});

it('selects prunable records using the configured retention windows', function (): void {
    config()->set('checkpoint.retention.default_days', 30);
    config()->set('checkpoint.retention.failed_days', 365);
    config()->set('checkpoint.retention.tiers', ['hot' => 14, 'warm' => 60, 'cold' => 180]);

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

it('prunes externalized command output artifacts with the command run rows', function (): void {
    Storage::fake('local');

    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.filesystem.disk', 'local');
    config()->set('checkpoint.output.filesystem.path_prefix', 'checkpoint/pruned-output');
    config()->set('checkpoint.retention.default_days', 30);
    config()->set('checkpoint.retention.failed_days', 365);
    config()->set('checkpoint.retention.tiers', ['hot' => 14, 'warm' => 60, 'cold' => 180]);

    $prunable = CommandRun::factory()->succeeded()->create([
        'created_at' => Date::now()->subDays(45),
        'updated_at' => Date::now()->subDays(45),
        'command_output' => 'preview',
        'metadata' => [
            'output_storage' => [
                'driver' => 'filesystem',
                'externalized' => true,
                'disk' => 'local',
                'path' => 'checkpoint/pruned-output/command-run-100.log',
                'stored_bytes' => 128,
                'inline_bytes' => 7,
            ],
        ],
    ]);

    Storage::disk('local')->put('checkpoint/pruned-output/command-run-100.log', 'full artifact');

    $retained = CommandRun::factory()->failed()->create([
        'created_at' => Date::now()->subDays(5),
        'updated_at' => Date::now()->subDays(5),
    ]);

    $deleted = (new CommandRun)->pruneAll();

    expect($deleted)->toBe(1)
        ->and(CommandRun::query()->find($prunable->getKey()))->toBeNull()
        ->and(CommandRun::query()->find($retained->getKey()))->not->toBeNull();

    Storage::disk('local')->assertMissing('checkpoint/pruned-output/command-run-100.log');
});
