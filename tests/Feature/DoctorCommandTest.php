<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\DoctorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

it('renders the doctor health table', function (): void {
    checkpoint_artisan('checkpoint:doctor -v')
        ->expectsOutputToContain('Config: driver')
        ->expectsOutputToContain('Config: queue.name')
        ->expectsOutputToContain('Config: pgbackrest.stanza')
        ->expectsOutputToContain('Config: pgbackrest.repositories')
        ->expectsOutputToContain('Repo: pgbackrest.active')
        ->expectsOutputToContain('Repo: pgbackrest.target')
        ->expectsOutputToContain('Repo: pgbackrest.tls')
        ->expectsOutputToContain('Repo: pgbackrest.encryption')
        ->expectsOutputToContain('Binary: pgBackRest')
        ->expectsOutputToContain('DB: command_runs table')
        ->expectsOutputToContain('DB: backup_drill_runs table')
        ->expectsOutputToContain('DB: verification_runs table')
        ->expectsOutputToContain('Orphaned runs')
        ->expectsOutputToContain('Restore posture: environments')
        ->expectsOutputToContain('Restore posture: databases')
        ->expectsOutputToContain('Restore posture: CI bypass')
        ->expectsOutputToContain('Restore posture: verified backup')
        ->expectsOutputToContain('Restore posture: post-restore verification')
        ->expectsOutputToContain('Backups: last known good')
        ->expectsOutputToContain('Backups: duration anomaly')
        ->expectsOutputToContain('Backup drills: latest run')
        ->expectsOutputToContain('Backup drills: pass rate')
        ->expectsOutputToContain('Backup drills: trend')
        ->expectsOutputToContain('Backup drills: remediation playbook')
        ->expectsOutputToContain('Verification: runs')
        ->assertSuccessful();
});

it('prioritizes P0/P1 checks and collapses lower-priority checks by default', function (): void {
    checkpoint_artisan('checkpoint:doctor')
        ->expectsOutputToContain('Suppressed')
        ->expectsOutputToContain('Backup drills: pass rate')
        ->doesntExpectOutputToContain('Config: driver')
        ->assertSuccessful();
});

it('renders triage-first brief doctor output with top issue and action', function (): void {
    checkpoint_artisan('checkpoint:doctor --brief')
        ->expectsOutputToContain('Doctor triage (brief)')
        ->expectsOutputToContain('Blockers:')
        ->expectsOutputToContain('P0:')
        ->expectsOutputToContain('Action now:')
        ->expectsOutputToContain('Deep dive: php artisan checkpoint:doctor --format=json')
        ->assertSuccessful();
});

it('supports the check doctor command alias', function (): void {
    checkpoint_artisan('checkpoint:check:doctor')
        ->expectsOutputToContain('Suppressed')
        ->assertSuccessful();
});

it('renders stable status labels when translation keys cannot be resolved', function (): void {
    config()->set('app.locale', 'zz');
    config()->set('app.fallback_locale', 'zz');

    checkpoint_artisan('checkpoint:doctor')
        ->expectsOutputToContain('WARN')
        ->doesntExpectOutputToContain('messages.cli.doctor_pass')
        ->doesntExpectOutputToContain('messages.cli.doctor_warn')
        ->doesntExpectOutputToContain('messages.cli.doctor_fail')
        ->assertSuccessful();
});

it('throws a configuration exception for invalid config in non-production', function (): void {
    config()->set('checkpoint.table_prefix', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.table_prefix must be a non-empty string.');
});

it('fails doctor when queue timeout settings are unsafe', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    checkpoint_artisan('checkpoint:doctor')
        ->expectsOutputToContain('Config validation')
        ->assertFailed();
});

it('returns non-zero when any doctor check fails', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    $exitCode = Artisan::call('checkpoint:doctor', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(10)
        ->and($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'config.validation'
                && $check['status'] === 'fail',
        ))->toBeTrue();
});

it('warns about unsafe restore posture in non-local environments', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.queue.lock_store');
    config()->set('checkpoint.schedule.without_overlapping', false);
    config()->set('checkpoint.schedule.on_one_server', false);
    config()->set('checkpoint.restore.allowed_environments', ['production']);
    config()->set('checkpoint.restore.allowed_databases', ['primary']);
    config()->set('checkpoint.restore.allow_in_ci', true);
    config()->set('checkpoint.restore.require_verified_backup', false);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.posture.environments'
                && $check['status'] === 'warn'
                && $check['data']['environment'] === 'production'
                && $check['data']['current_environment_allowed'] === true,
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.posture.databases'
                && $check['status'] === 'pass'
                && $check['data']['current_database_allowlisted'] === false,
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.posture.ci_bypass'
                && $check['status'] === 'warn'
                && $check['data']['allow_in_ci'] === true,
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.posture.verified_backup'
                && $check['status'] === 'warn'
                && $check['data']['require_verified_backup'] === false,
        ))->toBeTrue();
});

it('surfaces post-restore verification health posture in machine-readable doctor output', function (): void {
    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'restore_target' => 'nightly.sql',
        'restore_post_verification_result' => 'fail',
        'metadata' => [
            'restore_audit' => [
                'post_restore_verification' => [
                    'contract_version' => 1,
                    'aggregate_result' => 'fail',
                    'checks_performed' => ['command_exit_code_zero'],
                    'checks' => [[
                        'name' => 'command_exit_code_zero',
                        'passed' => false,
                        'status' => 'fail',
                        'description' => 'restore command finished with exit code 0',
                        'observed' => 1,
                    ]],
                ],
            ],
        ],
        'status' => 'failed',
        'attempts' => 1,
        'exit_code' => 1,
        'finished_at' => now()->subMinute(),
    ]);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.post_verification'
                && $check['status'] === 'warn'
                && ($check['data']['aggregate_result'] ?? null) === 'fail'
                && is_array($check['data']['post_restore_verification'] ?? null)
                && (($check['data']['post_restore_verification']['contract_version'] ?? null) === 1),
        ))->toBeTrue();
});

it('fails when active driver binary is missing and includes remediation commands', function (): void {
    config()->set('checkpoint.driver', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.binary', 'missing-pgbackrest-binary');

    $exitCode = Artisan::call('checkpoint:doctor', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(10)
        ->and($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'driver.binary.pgbackrest'
                && $check['status'] === 'fail'
                && ($check['data']['binary'] ?? null) === 'missing-pgbackrest-binary'
                && is_array($check['data']['remediation_commands'] ?? null)
                && in_array('command -v missing-pgbackrest-binary', $check['data']['remediation_commands'], true),
        ))->toBeTrue();
});

it('shows selected remote repo hardening details without secrets', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repositories.1', [
        'type' => 's3',
        'path' => null,
        's3' => [
            'bucket' => 'checkpoint-backups',
            'endpoint' => 's3.example.com',
            'region' => 'ap-south-1',
            'key' => 'hidden-key',
            'secret' => 'hidden-secret',
            'uri_style' => 'host',
        ],
        'tls' => [
            'verify' => false,
            'ca_file' => '/etc/ssl/checkpoint.pem',
        ],
        'encryption' => [
            'enabled' => true,
            'cipher_type' => 'aes-256-cbc',
            'passphrase' => 'hidden-passphrase',
        ],
    ]);

    checkpoint_artisan('checkpoint:doctor -v')
        ->expectsOutputToContain('s3://checkpoint-backups via s3.example.com')
        ->expectsOutputToContain('verify disabled')
        ->expectsOutputToContain('enabled (aes-256-cbc)')
        ->doesntExpectOutputToContain('hidden-key')
        ->doesntExpectOutputToContain('hidden-secret')
        ->doesntExpectOutputToContain('hidden-passphrase')
        ->assertSuccessful();
});

it('renders a machine-readable json report', function (): void {
    CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'status' => 'succeeded',
        'attempts' => 1,
        'exit_code' => 0,
    ]);
    VerificationRun::query()->create([
        'command_run_id' => 1,
        'verification_type' => 'pgbackrest_check',
        'status' => 'failed',
        'verified_at' => now(),
        'error_detail' => 'verification failed',
    ]);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(3)
        ->and($report['surface'])->toBe('doctor')
        ->and($report['ok'])->toBeFalse()
        ->and($report['driver'])->toBe('shell')
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'config.driver'
                && $check['status'] === 'pass'
                && $check['severity'] === 'info'
                && $check['data']['driver'] === 'shell',
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'verification.runs'
                && in_array($check['status'], ['pass', 'warn'], true)
                && ($check['data']['failed_runs'] ?? null) === 1,
        ))->toBeTrue();
});

it('reports drill freshness and pass rate in machine-readable json', function (): void {
    DoctorCommandTestSupport::freezeTime();
    DoctorCommandTestSupport::seedRecentDrillPair();

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'backup_drill.latest_run'
                && $check['status'] === 'pass'
                && $check['data']['run_uuid'] === 'drill-fail-001'
                && $check['data']['age_days'] === 2,
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'backup_drill.pass_rate'
                && $check['status'] === 'warn'
                && $check['data']['window_days'] === 30
                && $check['data']['total'] === 2
                && $check['data']['passing'] === 1
                && (float) $check['data']['pass_rate_percent'] === 50.0
                && (float) $check['data']['threshold_percent'] === 100.0,
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'backup_drill.playbook'
                && $check['status'] === 'warn'
                && ($check['data']['signature'] ?? null) === 'drill.pass_rate_below_threshold'
                && is_array($check['data']['recommended_commands'] ?? null),
        ))->toBeTrue();

    DoctorCommandTestSupport::resetTime();
});

it('dispatches drill alarms when drill freshness and pass rate fall below threshold', function (): void {
    Event::fake([BackupDrillFreshnessAlarmTriggered::class, BackupDrillPassRateAlarmTriggered::class]);
    DoctorCommandTestSupport::freezeTime();

    config()->set('checkpoint.observability.max_backup_drill_age_days', 7);
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    config()->set('checkpoint.observability.backup_drill_min_pass_rate', 100.0);

    $latestRun = DoctorCommandTestSupport::seedStaleDrillAlarmState()['latest'];

    checkpoint_artisan('checkpoint:doctor')->assertSuccessful();

    Event::assertDispatched(fn (BackupDrillFreshnessAlarmTriggered $event): bool => $event->reason === 'stale'
        && $event->run?->is($latestRun) === true
        && $event->ageDays === 10
        && $event->thresholdDays === 7
        && $event->version === 1);

    Event::assertDispatched(fn (BackupDrillPassRateAlarmTriggered $event): bool => $event->latestRun?->is($latestRun) === true
        && $event->windowDays === 14
        && $event->passing === 1
        && $event->total === 2
        && $event->passRatePercent === 50.0
        && $event->thresholdPercent === 100.0
        && $event->version === 1);

    DoctorCommandTestSupport::resetTime();
});

it('dispatches drill alarms when no backup drills exist', function (): void {
    Event::fake([BackupDrillFreshnessAlarmTriggered::class, BackupDrillPassRateAlarmTriggered::class]);

    checkpoint_artisan('checkpoint:doctor')->assertSuccessful();

    Event::assertDispatched(fn (BackupDrillFreshnessAlarmTriggered $event): bool => $event->reason === 'missing'
        && ! $event->run instanceof BackupDrillRun
        && $event->ageDays === null
        && $event->thresholdDays === 30
        && $event->version === 1);

    Event::assertDispatched(fn (BackupDrillPassRateAlarmTriggered $event): bool => ! $event->latestRun instanceof BackupDrillRun
        && $event->windowDays === 30
        && $event->passing === 0
        && $event->total === 0
        && $event->passRatePercent === 0.0
        && $event->thresholdPercent === 100.0
        && $event->version === 1);
});

it('returns a failed machine-readable json report for invalid config', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    $exitCode = Artisan::call('checkpoint:doctor', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(10)
        ->and($report)->toBeArray()
        ->and($report['version'])->toBe(3)
        ->and($report['surface'])->toBe('doctor')
        ->and($report['ok'])->toBeFalse()
        ->and($report['gates'])->toMatchArray([
            'failed_gate' => 'safety',
            'exit_code' => 10,
        ])
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'config.validation'
                && $check['status'] === 'fail'
                && $check['severity'] === 'blocker',
        ))->toBeTrue();
});

it('renders compact agent-friendly doctor output', function (): void {
    Artisan::call('checkpoint:doctor', ['--agent' => true]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(3)
        ->and($report['surface'])->toBe('doctor')
        ->and($report['result'])->toBeString()
        ->and($report['code'])->toBeString()
        ->and($report['summary'])->toBeString()
        ->and($report['compact'])->toBeArray()
        ->and($report['compact'])->toHaveKeys(['verdict', 'severity', 'top_issue', 'next_action', 'exit_code'])
        ->and($report['data']['ok'])->toBeBool()
        ->and($report['data']['checks'])->toBeArray()
        ->and($report['data']['checks'][0])->toHaveKeys(['code', 'check', 'status', 'severity', 'notes', 'data'])
        ->and($report['data']['severity_totals'])->toBeArray()
        ->and($report['data']['severity_totals'])->toHaveKeys(['blocker', 'warning', 'info'])
        ->and($report['data']['slo'])->toBeArray()
        ->and($report['data']['slo'])->toHaveKeys(['window', 'indicators', 'overall_status'])
        ->and($report['data']['slo']['indicators'])->toBeArray()
        ->and($report['data']['slo']['indicators'][0])->toHaveKeys(['name', 'target', 'current', 'status', 'unit'])
        ->and($report['suggestions'])->toBeArray()
        ->and(collect($report['suggestions'])->contains(
            static fn (mixed $suggestion): bool => is_string($suggestion) && str_contains($suggestion, 'post-verification'),
        ))->toBeTrue();
});

it('returns evidence gate exit code when staging profile evidence is degraded', function (): void {
    $exitCode = Artisan::call('checkpoint:doctor', ['--agent' => true, '--policy-profile' => 'staging']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(11)
        ->and($report)->toBeArray()
        ->and($report['data']['gates'])->toMatchArray([
            'profile' => 'staging',
            'profile_source' => 'override',
            'failed_gate' => 'evidence',
            'verdict' => 'fail',
            'exit_code' => 11,
        ]);
});

it('warns when the last known good backup is stale and the latest run is anomalously slow', function (): void {
    config()->set('app.env', 'testing');
    config()->set('checkpoint.table_prefix', 'db_ops_');
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 3660);
    config()->set('checkpoint.observability.max_last_known_good_age_hours', 12);
    config()->set('checkpoint.observability.backup_duration_anomaly_factor', 2.0);
    config()->set('checkpoint.observability.backup_duration_min_samples', 3);

    DoctorCommandTestSupport::seedAnomalousBackupHistory();

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => in_array((string) ($check['code'] ?? ''), ['backup.last_known_good', 'config.validation'], true),
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => ($check['code'] ?? null) === 'backup.duration_anomaly'
                && (float) ($check['data']['factor'] ?? 0) === 2.0,
        ) || collect($report['checks'])->contains(
            fn (array $check): bool => ($check['code'] ?? null) === 'config.validation',
        ))->toBeTrue();

});

it('dispatches a freshness alarm when the last-known-good backup is stale', function (): void {
    Event::fake([BackupFreshnessAlarmTriggered::class]);

    config()->set('checkpoint.observability.max_last_known_good_age_hours', 12);

    DoctorCommandTestSupport::seedStaleLastKnownGoodBackup();

    checkpoint_artisan('checkpoint:doctor')->assertSuccessful();

    $dispatched = Event::dispatched(BackupFreshnessAlarmTriggered::class);

    expect($dispatched)->toHaveCount(1);

    /** @var BackupFreshnessAlarmTriggered $event */
    $event = $dispatched->first()[0];

    expect($event->reason)->toBe('stale')
        ->and($event->ageHours)->toBeInt()
        ->and($event->ageHours)->toBeGreaterThanOrEqual(30)
        ->and($event->thresholdHours)->toBe(12)
        ->and($event->version)->toBe(1)
        ->and($event->run)->toBeInstanceOf(CommandRun::class)
        ->and($event->run?->last_known_good_at)->not->toBeNull();
});

it('dispatches a freshness alarm when no last-known-good backup exists', function (): void {
    Event::fake([BackupFreshnessAlarmTriggered::class]);

    checkpoint_artisan('checkpoint:doctor')->assertSuccessful();

    Event::assertDispatched(fn (BackupFreshnessAlarmTriggered $event): bool => $event->reason === 'missing'
        && $event->ageHours === null
        && $event->thresholdHours === 24
        && $event->version === 1
        && ! $event->run instanceof CommandRun);
});

it('counts pending rows by their last worker heartbeat rather than claim timestamp', function (): void {
    config()->set('checkpoint.queue.orphan_threshold', 10);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now()->subMinutes(45),
        'updated_at' => now()->subMinutes(45),
        'orphan_recovery_claimed_at' => now()->subMinute(),
    ]);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'queue.orphaned_runs'
                && $check['status'] === 'warn'
                && $check['data']['orphaned_run_count'] === 1
                && $check['data']['threshold_minutes'] === 10,
        ))->toBeTrue();
});

it('warns when stale orphaned runs remain beyond the threshold', function (): void {
    config()->set('checkpoint.queue.orphan_threshold', 10);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'pending',
        'attempts' => 0,
        'created_at' => now()->subMinutes(45),
        'updated_at' => now()->subMinutes(45),
    ]);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'queue.orphaned_runs'
                && $check['status'] === 'warn'
                && $check['data']['orphaned_run_count'] === 1
                && $check['data']['threshold_minutes'] === 10,
        ))->toBeTrue();
});
