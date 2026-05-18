<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('computes status summary correctly with high command-run volume', function (): void {
    Date::setTestNow('2026-03-15 12:00:00');

    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 30);

    CommandRun::factory()->count(120)->succeeded()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    CommandRun::factory()->count(25)->pending()->create([
        'operation' => 'logical_backup',
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    CommandRun::factory()->count(15)->running()->create([
        'operation' => 'physical_backup',
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(2),
    ]);

    CommandRun::factory()->count(40)->failed()->create([
        'operation' => 'logical_restore_file',
        'created_at' => now()->subHours(4),
        'updated_at' => now()->subHours(4),
    ]);

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'backup_type' => 'full',
        'backup_label' => '20260315-010101F',
        'verification_state' => 'verified',
        'verified_at' => now()->subMinute(),
        'last_known_good_at' => now()->subMinute(),
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
    ]);

    Artisan::call('checkpoint:status', ['--summary' => true, '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['summary'])->toMatchArray([
            'pending_runs' => 25,
            'running_runs' => 15,
            'failed_runs_24h' => 40,
        ])
        ->and($report['summary']['last_known_good_backup']['label'])->toContain('20260315-010101F')
        ->and($report['summary']['latest_verified_backup']['label'])->toContain('20260315-010101F');

    Date::setTestNow();
});

it('returns bounded recent report results for high command-run volume', function (): void {
    Date::setTestNow('2026-03-15 12:00:00');

    CommandRun::factory()->count(300)->succeeded()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'created_at' => now()->subHours(6),
        'updated_at' => now()->subHours(6),
    ]);

    Artisan::call('checkpoint:doctor', ['--full' => true, '--limit' => 50, '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['limit'])->toBe(50)
        ->and($report['recent_runs'])->toHaveCount(50)
        ->and($report['recent_runs'][0]['id'])->toBeGreaterThan($report['recent_runs'][49]['id']);

    Date::setTestNow();
});

it('counts stale orphaned runs correctly at high volume', function (): void {
    Date::setTestNow('2026-03-15 12:00:00');
    config()->set('checkpoint.queue.orphan_threshold', 10);

    CommandRun::factory()->count(180)->pending()->create([
        'operation' => 'logical_backup',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(45),
        'orphan_recovery_claimed_at' => now()->subMinute(),
    ]);

    CommandRun::factory()->count(20)->pending()->create([
        'operation' => 'logical_backup',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'queue.orphaned_runs'
                && $check['status'] === 'warn'
                && $check['data']['orphaned_run_count'] === 180
                && $check['data']['threshold_minutes'] === 10,
        ))->toBeTrue();

    Date::setTestNow();
});

it('re-dispatches stale orphan batches with correct aggregate metadata at scale', function (): void {
    Date::setTestNow('2026-03-15 12:00:00');

    Bus::fake();
    Event::fake([QueueLagDetected::class, OrphanRunRedispatched::class]);

    config()->set('checkpoint.queue.orphan_threshold', 10);
    config()->set('checkpoint.queue.orphan_batch_size', 25);
    config()->set('checkpoint.queue.orphan_event_max_ids', 10);

    CommandRun::factory()->count(120)->pending()->create([
        'operation' => 'logical_backup',
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subMinutes(35),
    ]);

    checkpoint_artisan('checkpoint:health-check')->assertSuccessful();

    Bus::assertDispatchedTimes(ProcessCommandRunJob::class, 120);
    Event::assertDispatchedTimes(OrphanRunRedispatched::class, 120);

    $lagEvents = Event::dispatched(QueueLagDetected::class);
    expect($lagEvents)->toHaveCount(1);

    /** @var QueueLagDetected $lagEvent */
    $lagEvent = $lagEvents->first()[0];

    expect($lagEvent->staleRunCount)->toBe(120)
        ->and($lagEvent->thresholdMinutes)->toBe(10)
        ->and($lagEvent->staleRunIds)->toHaveCount(10)
        ->and($lagEvent->staleRunIdsTruncated)->toBeTrue();

    Date::setTestNow();
});
