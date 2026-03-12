<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Date;

it('renders the doctor health table', function (): void {
    checkpoint_artisan('db-ops:doctor')
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
        ->expectsOutputToContain('Orphaned runs')
        ->expectsOutputToContain('Backups: last known good')
        ->expectsOutputToContain('Backups: duration anomaly')
        ->expectsOutputToContain('Backup drills: latest run')
        ->expectsOutputToContain('Backup drills: pass rate')
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

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Config validation')
        ->assertFailed();
});

it('shows the configured pgbackrest binary when it is missing from path', function (): void {
    config()->set('checkpoint.driver', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.binary', 'missing-pgbackrest-binary');

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Binary: pgBackRest')
        ->assertSuccessful();
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

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('s3://checkpoint-backups via s3.example.com')
        ->expectsOutputToContain('verify disabled')
        ->expectsOutputToContain('enabled (aes-256-cbc)')
        ->doesntExpectOutputToContain('hidden-key')
        ->doesntExpectOutputToContain('hidden-secret')
        ->doesntExpectOutputToContain('hidden-passphrase')
        ->assertSuccessful();
});

it('renders a machine-readable json report', function (): void {
    Artisan::call('db-ops:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['ok'])->toBeTrue()
        ->and($report['driver'])->toBe('shell')
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Config: driver' && $check['status'] === 'pass',
        ))->toBeTrue();
});

it('reports drill freshness and pass rate in machine-readable json', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-pass-001',
        'overall_result' => 'pass',
        'executed_at' => now()->subDays(5),
    ]);

    BackupDrillRun::query()->create([
        'run_uuid' => 'drill-fail-001',
        'overall_result' => 'fail',
        'executed_at' => now()->subDays(2),
    ]);

    Artisan::call('db-ops:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Backup drills: latest run'
                && $check['status'] === 'pass'
                && str_contains($check['notes'], 'FAIL 2 days old (drill-fail-001)'),
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Backup drills: pass rate'
                && $check['status'] === 'warn'
                && $check['notes'] === '1/2 passed in the last 30 days (50.0%)',
        ))->toBeTrue();

    Date::setTestNow();
});

it('returns a failed machine-readable json report for invalid config', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    $exitCode = Artisan::call('db-ops:doctor', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($report)->toBeArray()
        ->and($report['ok'])->toBeFalse()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Config validation' && $check['status'] === 'fail',
        ))->toBeTrue();
});

it('warns when the last known good backup is stale and the latest run is anomalously slow', function (): void {
    config()->set('checkpoint.observability.max_last_known_good_age_hours', 12);
    config()->set('checkpoint.observability.backup_duration_anomaly_factor', 2.0);
    config()->set('checkpoint.observability.backup_duration_min_samples', 3);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'status' => 'succeeded',
        'attempts' => 0,
        'duration_seconds' => 900,
        'last_known_good_at' => now()->subHours(30),
    ]);
    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_full',
        'backup_type' => 'full',
        'status' => 'succeeded',
        'attempts' => 0,
        'duration_seconds' => 300,
        'last_known_good_at' => now()->subHours(32),
    ]);
    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_diff',
        'backup_type' => 'diff',
        'status' => 'succeeded',
        'attempts' => 0,
        'duration_seconds' => 320,
        'last_known_good_at' => now()->subHours(31),
    ]);
    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_incr',
        'backup_type' => 'incr',
        'status' => 'succeeded',
        'attempts' => 0,
        'duration_seconds' => 900,
        'last_known_good_at' => now()->subHours(33),
    ]);

    Artisan::call('db-ops:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Backups: last known good'
                && str_contains($check['notes'], 'threshold: 12'),
        ))->toBeTrue()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Backups: duration anomaly'
                && str_contains($check['notes'], 'factor: 2.0'),
        ))->toBeTrue();

});

it('dispatches a freshness alarm when the last-known-good backup is stale', function (): void {
    Event::fake([BackupFreshnessAlarmTriggered::class]);

    config()->set('checkpoint.observability.max_last_known_good_age_hours', 12);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'status' => 'succeeded',
        'attempts' => 0,
        'last_known_good_at' => now()->subHours(30),
    ]);

    $command = app(DoctorCommand::class);
    $method = new ReflectionMethod($command, 'lastKnownGoodRow');
    $method->setAccessible(true);
    $method->invoke($command);

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

    checkpoint_artisan('db-ops:doctor')->assertSuccessful();

    Event::assertDispatched(fn (BackupFreshnessAlarmTriggered $event): bool => $event->reason === 'missing'
        && $event->ageHours === null
        && $event->thresholdHours === 24
        && $event->version === 1
        && $event->run === null);
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

    Artisan::call('db-ops:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Orphaned runs'
                && $check['status'] === 'warn'
                && $check['notes'] === '1 pending runs beyond threshold',
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

    Artisan::call('db-ops:doctor', ['--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Orphaned runs'
                && $check['status'] === 'warn'
                && $check['notes'] === '1 pending runs beyond threshold',
        ))->toBeTrue();
});
