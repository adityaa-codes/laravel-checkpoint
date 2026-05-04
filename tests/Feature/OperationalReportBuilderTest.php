<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
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
        'operation' => 'physical_backup',
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

    $summary = resolve(OperationalReportBuilder::class)->summary();

    expect($summary)->toHaveKeys([
        'last_known_good_backup',
        'latest_verified_backup',
        'latest_backup_drill',
        'backup_drill_pass_rate',
        'backup_drill_trend',
        'backup_drill_remediation_playbook',
        'backup_drill_pass_rate_30d',
    ])->and($summary['backup_drill_pass_rate'])->toMatchArray([
        'label' => '0/1 (0.0%)',
        'window_days' => 14,
        'total' => 1,
        'passing' => 0,
        'pass_rate_percent' => 0.0,
    ])->and($summary['backup_drill_trend'])->toMatchArray([
        'window_days' => 14,
        'sample_size' => 1,
        'latest_result' => 'fail',
        'latest_run_uuid' => 'drill-fail-001',
        'trajectory' => 'insufficient_data',
        'status' => 'stable',
    ])->and($summary['backup_drill_remediation_playbook'])->toMatchArray([
        'signature' => 'drill.pass_rate_below_threshold',
        'severity' => 'warn',
    ])->and($summary['backup_drill_pass_rate_30d'])->toBe($summary['backup_drill_pass_rate']);

    Date::setTestNow();
});

it('marks shared health output as not ok when warnings are present', function (): void {
    $checks = resolve(OperationalReportBuilder::class)->healthChecks();

    expect(resolve(OperationalReportBuilder::class)->healthOk($checks))->toBeFalse();
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

    resolve(OperationalReportBuilder::class)->healthChecks();
    resolve(OperationalReportBuilder::class)->healthChecks();

    Event::assertDispatchedTimes(BackupDrillPassRateAlarmTriggered::class, 1);

    Date::setTestNow('2026-03-11 12:06:00');
    resolve(OperationalReportBuilder::class)->healthChecks();

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

    VerificationRun::query()->create([
        'command_run_id' => 1,
        'verification_type' => 'physical_backup',
        'status' => 'verified',
        'verified_at' => now(),
        'metadata' => ['driver' => 'pgbasebackup'],
    ]);

    $payload = resolve(OperationalReportBuilder::class)->reportPayload(5);

    expect($payload)->toHaveKeys(['recent_runs', 'summary', 'verification', 'health'])
        ->and($payload['recent_runs'])->toHaveCount(1)
        ->and($payload['summary'])->toHaveKey('last_known_good_backup')
        ->and($payload['summary']['latest_restore_run'])->toHaveKey('post_restore_verification')
        ->and($payload['verification'])->toMatchArray([
            'total_runs' => 1,
            'verified_runs' => 1,
            'failed_runs' => 0,
            'health_status' => 'pass',
        ])
        ->and($payload['health'])->toHaveKeys(['ok', 'checks']);

    Date::setTestNow();
});

it('includes post-restore verification contract in recent run payloads', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'restore_target' => 'nightly.sql',
        'restore_post_verification_result' => 'fail',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
        'finished_at' => now()->subMinute(),
        'metadata' => [
            'restore_audit' => [
                'confirmation_satisfied_via' => 'token',
                'verified_signal_run_id' => 22,
                'post_restore_verification' => [
                    'contract_version' => 1,
                    'command_run_id' => 1,
                    'operation' => 'logical_restore_file',
                    'aggregate_result' => 'fail',
                    'checks_performed' => [
                        'restore_audit_recorded',
                        'restore_target_recorded',
                        'command_exit_code_zero',
                        'verified_backup_signal_linkage',
                    ],
                ],
            ],
        ],
    ]);

    $runs = resolve(OperationalReportBuilder::class)->recentRuns(1);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['post_restore_verification'])->toMatchArray([
            'contract_version' => 1,
            'aggregate_result' => 'fail',
            'checks_performed' => [
                'restore_audit_recorded',
                'restore_target_recorded',
                'command_exit_code_zero',
                'verified_backup_signal_linkage',
            ],
        ]);

    Date::setTestNow();
});

it('exposes verification health details in health checks', function (): void {
    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
    ]);

    VerificationRun::query()->create([
        'command_run_id' => 1,
        'verification_type' => 'physical_backup',
        'status' => 'failed',
        'verified_at' => now(),
        'error_detail' => 'Verification command failed',
        'metadata' => ['driver' => 'pgbasebackup'],
    ]);

    $checks = resolve(OperationalReportBuilder::class)->healthChecks();

    expect(collect($checks)->contains(
        fn (array $check): bool => $check['code'] === 'verification.runs'
            && $check['status'] === 'warn'
            && ($check['data']['failed_runs'] ?? null) === 1
            && ($check['data']['total_runs'] ?? null) === 1,
    ))->toBeTrue();
});

it('emits drill trend health checks from drill history', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-002',
        'overall_result' => 'fail',
        'executed_at' => now()->subDays(1),
    ]);

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-001',
        'overall_result' => 'fail',
        'executed_at' => now()->subDays(2),
    ]);

    $checks = resolve(OperationalReportBuilder::class)->healthChecks();

    expect(collect($checks)->contains(
        fn (array $check): bool => $check['code'] === 'backup_drill.trend'
            && $check['status'] === 'warn'
            && ($check['data']['status'] ?? null) === 'degrading'
            && ($check['data']['streak']['type'] ?? null) === 'fail'
            && ($check['data']['streak']['length'] ?? null) === 2,
    ))->toBeTrue();

    expect(collect($checks)->contains(
        fn (array $check): bool => $check['code'] === 'backup_drill.playbook'
            && $check['status'] === 'warn'
            && ($check['data']['signature'] ?? null) === 'drill.degrading_trend',
    ))->toBeTrue();

    Date::setTestNow();
});

it('includes replication metadata in recent run payloads when available', function (): void {
    CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-source","destination":"pgsql://[REDACTED]"}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => [
                    'kind' => 'config_profile',
                    'identifier' => 'pg-source',
                    'redacted' => 'profile:pg-source',
                ],
                'destination' => [
                    'kind' => 'dsn',
                    'identifier' => null,
                    'redacted' => 'pgsql://[REDACTED]@db.internal',
                ],
                'queue_only' => true,
                'dry_run_requested' => true,
                'apply_requested' => false,
                'force_requested' => false,
                'overwrite_destination' => false,
                'governance_preflight' => [
                    'policy_version' => 1,
                    'mode' => 'dry_run',
                    'allowed' => true,
                    'blocked_reasons' => [],
                ],
                'result' => 'dry_run_only',
                'sanity' => [
                    'method' => 'artifact_hash',
                ],
                'failure_analysis' => [
                    'category' => 'dns_network_connection_refused',
                    'signature' => 'DNS resolution or network connectivity to source/destination failed.',
                    'immediate_fix' => 'Validate host, port, and network path; then rerun dry-run.',
                    'deeper_diagnostics' => [
                        'Check DNS resolution, firewall rules, VPN routes, and security group policies.',
                    ],
                    'diagnostics' => [
                        'stage' => 'dry_run_export',
                        'excerpt' => 'could not translate host name',
                    ],
                ],
            ],
        ],
    ]);

    $runs = resolve(OperationalReportBuilder::class)->recentRuns(1);

    expect($runs)->toHaveCount(1)
        ->and($runs[0]['operation'])->toBe('replication_sync')
        ->and($runs[0])->toHaveKey('replication')
        ->and($runs[0]['replication'])->toMatchArray([
            'engine' => 'pgsql',
            'queue_only' => true,
            'dry_run_requested' => true,
            'apply_requested' => false,
            'force_requested' => false,
            'overwrite_destination' => false,
            'governance_preflight' => [
                'policy_version' => 1,
                'mode' => 'dry_run',
                'allowed' => true,
                'blocked_reasons' => [],
            ],
            'result' => 'dry_run_only',
        ])
        ->and($runs[0]['replication']['failure_analysis'] ?? null)->toMatchArray([
            'category' => 'dns_network_connection_refused',
            'immediate_fix' => 'Validate host, port, and network path; then rerun dry-run.',
        ])
        ->and($runs[0]['replication']['destination'])->toMatchArray([
            'kind' => 'dsn',
            'redacted' => 'pgsql://[REDACTED]@db.internal',
        ]);
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
        'restore_post_verification_result' => 'pass',
        'finished_at' => now()->subMinute(),
        'metadata' => [
            'restore_audit' => [
                'confirmation_satisfied_via' => 'stale',
                'verified_signal_run_id' => 3,
                'blast_radius' => [
                    'enabled' => true,
                    'score' => 60,
                    'status' => 'warn',
                    'warn_score' => 50,
                    'block_score' => 80,
                    'factors' => [
                        ['name' => 'environment', 'weight' => 30, 'contributes' => true, 'note' => 'restore running in production environment'],
                    ],
                ],
                'post_restore_verification' => [
                    'aggregate_result' => 'stale-fail',
                ],
            ],
        ],
    ]);

    $summary = resolve(OperationalReportBuilder::class)->summary();

    expect($summary['latest_restore_run'])->toMatchArray([
        'operation' => 'logical_restore_latest',
        'status' => 'succeeded',
        'target' => '/managed/export/latest',
    ])->and($summary['latest_restore_run']['label'])->toContain('confirm=token')
        ->and($summary['latest_restore_run']['label'])->toContain('verified_run=77')
        ->and($summary['latest_restore_run']['label'])->toContain('post_verify=pass')
        ->and($summary['latest_restore_run']['label'])->not->toContain('verified_run=3')
        ->and($summary['latest_restore_run']['label'])->not->toContain('stale-fail')
        ->and($summary['latest_restore_run']['blast_radius'])->toMatchArray([
            'score' => 60,
            'status' => 'warn',
        ])
        ->and($summary['latest_restore_run']['audit'])->toMatchArray([
            'confirmation_satisfied_via' => 'token',
            'verified_signal_run_id' => 77,
            'post_restore_verification' => [
                'aggregate_result' => 'pass',
            ],
        ])
        ->and($summary['latest_restore_run']['post_restore_verification'])->toMatchArray([
            'aggregate_result' => 'pass',
        ]);

    Date::setTestNow();
});
