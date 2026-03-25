<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Cache::store((string) config('checkpoint.queue.lock_store', 'array'))->flush();
});

it('builds shared summary payloads with compatibility aliases', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_full',
        'backup_type' => 'full',
        'backup_label' => '20260311-010101F',
        'verification_state' => 'verified',
        'verified_at' => now()->subMinutes(5),
        'last_known_good_at' => now()->subMinutes(10),
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
    ]);

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-001',
        'overall_result' => 'fail',
        'executed_at' => now()->subDays(2),
    ]);

    $summary = app(OperationalReportBuilder::class)->summary();

    expect($summary)->toHaveKeys([
        'last_known_good_backup',
        'latest_verified_backup',
        'latest_backup_drill',
        'backup_drill_pass_rate',
        'backup_drill_pass_rate_30d',
    ])->and($summary['backup_drill_pass_rate'])->toMatchArray([
        'label' => '0/1 (0.0%)',
        'window_days' => 14,
        'total' => 1,
        'passing' => 0,
        'pass_rate_percent' => 0.0,
    ])->and($summary['backup_drill_pass_rate_30d'])->toBe($summary['backup_drill_pass_rate']);

    Date::setTestNow();
});

it('marks shared health output as not ok when warnings are present', function (): void {
    $checks = app(OperationalReportBuilder::class)->healthChecks();

    expect(app(OperationalReportBuilder::class)->healthOk($checks))->toBeFalse();
});

it('deduplicates backup drill pass-rate alarm dispatches within cooldown windows', function (): void {
    Event::fake([BackupDrillPassRateAlarmTriggered::class]);
    Date::setTestNow('2026-03-11 12:00:00');

    config()->set('checkpoint.observability.alert_cooldown_seconds', 300);
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    config()->set('checkpoint.observability.backup_drill_min_pass_rate', 100.0);

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-001',
        'overall_result' => 'fail',
        'executed_at' => now()->subDays(1),
    ]);

    app(OperationalReportBuilder::class)->healthChecks();
    app(OperationalReportBuilder::class)->healthChecks();

    Event::assertDispatchedTimes(BackupDrillPassRateAlarmTriggered::class, 1);

    Date::setTestNow('2026-03-11 12:06:00');
    app(OperationalReportBuilder::class)->healthChecks();

    Event::assertDispatchedTimes(BackupDrillPassRateAlarmTriggered::class, 2);

    Date::setTestNow();
});

it('builds a combined report payload from a shared snapshot', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'last_known_good_at' => now()->subHour(),
    ]);

    $payload = app(OperationalReportBuilder::class)->reportPayload(5);

    expect($payload)->toHaveKeys(['recent_runs', 'summary', 'health'])
        ->and($payload['recent_runs'])->toHaveCount(1)
        ->and($payload['summary'])->toHaveKey('last_known_good_backup')
        ->and($payload['health'])->toHaveKeys(['ok', 'checks']);

    Date::setTestNow();
});

it('prefers denormalized restore audit fields in restore summaries', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    CommandRun::query()->create([
        'operation' => 'logical_restore_latest',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'restore_target' => '/managed/export/latest',
        'restore_confirmation_satisfied_via' => 'token',
        'restore_verified_signal_run_id' => 77,
        'finished_at' => now()->subMinute(),
        'metadata' => [
            'restore_audit' => [
                'confirmation_satisfied_via' => 'stale',
                'verified_signal_run_id' => 3,
            ],
        ],
    ]);

    $summary = app(OperationalReportBuilder::class)->summary();

    expect($summary['latest_restore_run'])->toMatchArray([
        'operation' => 'logical_restore_latest',
        'status' => 'succeeded',
        'target' => '/managed/export/latest',
    ])->and($summary['latest_restore_run']['label'])->toContain('confirm=token')
        ->and($summary['latest_restore_run']['label'])->toContain('verified_run=77')
        ->and($summary['latest_restore_run']['label'])->not->toContain('verified_run=3')
        ->and($summary['latest_restore_run']['audit'])->toMatchArray([
            'confirmation_satisfied_via' => 'token',
            'verified_signal_run_id' => 77,
        ]);

    Date::setTestNow();
});
