<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Tests\Support\CommandJsonFixtureSupport;
use Illuminate\Support\Facades\Artisan;

it('matches the status runs json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedStatusRuns();

    Artisan::call('checkpoint:status', ['--limit' => 1, '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/status-runs.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the status summary json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedOperatorState();

    Artisan::call('checkpoint:status', ['--summary' => true, '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/status-summary.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the doctor json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    CommandJsonFixtureSupport::seedMysqlDoctorInputs();
    CommandJsonFixtureSupport::seedOperatorState();

    CommandJsonFixtureSupport::withEmptyPath(function (): void {
        Artisan::call('checkpoint:doctor', ['--format' => 'json']);
    });

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/doctor.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the failed doctor json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    Artisan::call('checkpoint:doctor', ['--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/doctor-failure.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the operational report json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    CommandJsonFixtureSupport::seedOperatorState();

    CommandJsonFixtureSupport::withEmptyPath(function (): void {
        Artisan::call('checkpoint:report', ['--limit' => 2, '--format' => 'json']);
    });

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/report.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the failed operational report json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    Artisan::call('checkpoint:report', ['--limit' => 2, '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/report-failure.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the catalog export json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedCatalogExports();

    Artisan::call('checkpoint:catalog-export', ['--format' => 'json', '--limit' => 10]);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/catalog-export.json',
    );

    CommandJsonFixtureSupport::resetTime();
});

it('matches the pitr readiness json fixture', function (): void {
    CommandJsonFixtureSupport::freezeTime();
    CommandJsonFixtureSupport::seedPitrReadinessState();

    Artisan::call('checkpoint:pitr-readiness', ['target' => '2026-03-11 11:30:00', '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/pitr-readiness.json',
    );

    CommandJsonFixtureSupport::resetTime();
});
