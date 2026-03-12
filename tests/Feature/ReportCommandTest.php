<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;

it('renders a machine-readable operational report', function (): void {
    OperatorCommandTestSupport::freezeTime();
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('db-ops:report', ['--limit' => 2]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report['driver'])->toBe('shell')
        ->and($report['limit_requested'])->toBe(2)
        ->and($report['limit'])->toBe(2)
        ->and($report['recent_runs'])->toHaveCount(2)
        ->and($report['recent_runs'][0])->toMatchArray([
            'id' => 3,
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'restore_target' => 'nightly.sql',
        ])
        ->and($report['summary']['latest_restore_run'])->toMatchArray([
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'target' => 'nightly.sql',
        ])
        ->and($report['summary']['latest_backup_drill'])->toMatchArray([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
        ])
        ->and($report['summary']['backup_drill_pass_rate'])->toMatchArray([
            'label' => '1/2 (50.0%)',
            'window_days' => 14,
            'total' => 2,
            'passing' => 1,
            'pass_rate_percent' => 50.0,
        ])
        ->and($report['health']['ok'])->toBeFalse()
        ->and(collect($report['health']['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'backup_drill.pass_rate'
                && $check['status'] === 'warn',
        ))->toBeTrue();

    OperatorCommandTestSupport::resetTime();
});

it('returns a failed report when config validation fails', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    $exitCode = Artisan::call('db-ops:report');
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report)->toHaveKeys(['generated_at', 'driver', 'recent_runs', 'summary', 'health'])
        ->and($report['recent_runs'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['health']['ok'])->toBeFalse()
        ->and($report['health']['checks'])->toHaveCount(1)
        ->and($report['health']['checks'][0])->toMatchArray([
            'code' => 'config.validation',
            'check' => 'Config validation',
            'status' => 'fail',
        ]);
});

it('caps operational report recent runs to the configured reporting limit', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();
    config()->set('checkpoint.reporting.max_recent_runs', 1);

    Artisan::call('db-ops:report', ['--limit' => 50]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['recent_runs'])->toHaveCount(1);

    OperatorCommandTestSupport::resetTime();
});
