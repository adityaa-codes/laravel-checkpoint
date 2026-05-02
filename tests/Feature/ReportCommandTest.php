<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;

it('renders a machine-readable operational report', function (): void {
    OperatorCommandTestSupport::freezeTime();
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:report', ['--limit' => 2, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report['mode'])->toBe('full')
        ->and($report['driver'])->toBe('shell')
        ->and($report['limit_requested'])->toBe(2)
        ->and($report['limit'])->toBe(2)
        ->and($report['last_failed_run'])->toMatchArray([
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'exit_code' => 1,
            'failure_reason' => 'Command exited with code 1.',
        ])
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
        ->and($report['summary']['latest_restore_run']['blast_radius'])->toBeNull()
        ->and($report['summary']['latest_restore_run']['post_restore_verification'])->toMatchArray([
            'contract_version' => 1,
            'aggregate_result' => 'fail',
        ])
        ->and($report['recent_runs'][0]['post_restore_verification'])->toMatchArray([
            'contract_version' => 1,
            'aggregate_result' => 'fail',
        ])->and($report['recent_runs'][0]['restore_audit']['blast_radius'] ?? null)->toBeNull()
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
        ])->and($report['summary']['backup_drill_trend'])->toMatchArray([
            'window_days' => 14,
            'sample_size' => 2,
            'latest_result' => 'fail',
            'latest_run_uuid' => 'drill-fail-001',
            'trajectory' => 'stable',
            'status' => 'stable',
        ])->and($report['summary']['backup_drill_remediation_playbook'])->toMatchArray([
            'signature' => 'drill.pass_rate_below_threshold',
            'severity' => 'warn',
        ])->and($report['summary']['backup_drill_trend']['recent'])->toMatchArray([
            'results' => ['fail', 'pass'],
            'passing' => 1,
            'failing' => 1,
        ])
        ->and($report['breakdown'])->toMatchArray([
            'window' => ['failed_runs_hours' => 24],
            'totals' => [
                'groups' => 1,
                'runs' => 3,
                'failed_runs_24h' => 1,
            ],
        ])
        ->and($report['breakdown']['by_target']['driver:unknown|repo:none'])->toMatchArray([
            'driver' => 'unknown',
            'repository' => null,
            'stanza' => null,
            'failure_rate_percent' => 33.3,
            'health_status' => 'fail',
        ])
        ->and($report['breakdown']['by_target']['driver:unknown|repo:none']['runs'])->toMatchArray([
            'total' => 3,
            'failed' => 1,
            'failed_24h' => 1,
        ])
        ->and($report['verification'])->toMatchArray([
            'total_runs' => 2,
            'verified_runs' => 1,
            'failed_runs' => 1,
            'health_status' => 'warn',
        ])
        ->and($report['health']['ok'])->toBeFalse()
        ->and(collect($report['health']['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'backup_drill.pass_rate'
                && $check['status'] === 'warn',
        ))->toBeTrue()
        ->and(collect($report['health']['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'restore.post_verification'
                && $check['status'] === 'warn'
                && ($check['data']['aggregate_result'] ?? null) === 'fail',
        ))->toBeTrue()
        ->and(collect($report['health']['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'verification.runs'
                && $check['status'] === 'warn'
                && ($check['data']['failed_runs'] ?? null) === 1,
        ))->toBeTrue();

    OperatorCommandTestSupport::resetTime();
});

it('returns a failed report when config validation fails', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);
    config()->set('checkpoint.table_prefix', 'broken_');

    $exitCode = Artisan::call('checkpoint:report', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(10)
        ->and($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report)->toHaveKeys(['generated_at', 'driver', 'recent_runs', 'summary', 'verification', 'health', 'gates'])
        ->and($report['gates'])->toMatchArray([
            'failed_gate' => 'safety',
            'exit_code' => 10,
        ])
        ->and($report['recent_runs'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['verification'])->toMatchArray([
            'total_runs' => 0,
            'verified_runs' => 0,
            'failed_runs' => 0,
            'health_status' => 'warn',
        ])
        ->and($report['health']['ok'])->toBeFalse()
        ->and($report['health']['checks'])->toHaveCount(1)
        ->and($report['health']['checks'][0])->toMatchArray([
            'code' => 'config.validation',
            'check' => 'Config validation',
            'status' => 'fail',
        ]);
});

it('returns evidence gate exit code when staging profile evidence is degraded', function (): void {
    $exitCode = Artisan::call('checkpoint:report', ['--limit' => 2, '--agent' => true, '--policy-profile' => 'staging']);
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

it('caps operational report recent runs to the configured reporting limit', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();
    config()->set('checkpoint.reporting.max_recent_runs', 1);

    Artisan::call('checkpoint:report', ['--limit' => 50, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['recent_runs'])->toHaveCount(1);

    OperatorCommandTestSupport::resetTime();
});

it('renders report in table format by default', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    checkpoint_artisan('checkpoint:report --limit=2')
        ->expectsOutputToContain('Driver')
        ->expectsOutputToContain('Recent runs returned')
        ->expectsOutputToContain('Health OK')
        ->expectsOutputToContain('Drill remediation playbook')
        ->expectsOutputToContain('Latest restore post-verification')
        ->expectsOutputToContain('Check')
        ->expectsOutputToContain('Status')
        ->expectsOutputToContain('Suppressed')
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('renders triage-first brief report output with cause and action', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    checkpoint_artisan('checkpoint:report --limit=2 --brief')
        ->expectsOutputToContain('Checkpoint report (brief)')
        ->expectsOutputToContain('Failed runs (24h): 1')
        ->expectsOutputToContain('P0: ')
        ->expectsOutputToContain('Last failed: logical_restore_file [failed] (exit: 1) at 2026-03-11 11:42:00')
        ->expectsOutputToContain('Cause: Command exited with code 1.')
        ->expectsOutputToContain('Action now: Run php artisan checkpoint:report --limit=10 --format=json for full failure context.')
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('fails for unsupported report output formats', function (): void {
    checkpoint_artisan('checkpoint:report --format=xml')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();
});

it('renders compact agent-friendly report output', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:report', ['--limit' => 2, '--agent' => true]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report['result'])->toBeString()
        ->and($report['code'])->toBeString()
        ->and($report['summary'])->toBeString()
        ->and($report['compact'])->toBeArray()
        ->and($report['compact'])->toHaveKeys(['verdict', 'severity', 'top_issue', 'next_action', 'exit_code'])
        ->and($report['data']['driver'])->toBe('shell')
        ->and($report['data']['limit_requested'])->toBe(2)
        ->and($report['data']['limit'])->toBe(2)
        ->and($report['data']['recent_runs'])->toBeArray()
        ->and($report['data']['breakdown'])->toBeArray()
        ->and($report['data']['verification'])->toMatchArray([
            'total_runs' => 2,
            'verified_runs' => 1,
            'failed_runs' => 1,
            'health_status' => 'warn',
        ])
        ->and($report['data']['health'])->toBeArray()
        ->and($report['data']['slo'])->toBeArray()
        ->and($report['data']['slo'])->toHaveKeys(['window', 'indicators', 'overall_status'])
        ->and($report['data']['slo']['indicators'])->toBeArray()
        ->and($report['data']['slo']['indicators'][0])->toHaveKeys(['name', 'target', 'current', 'status', 'unit'])
        ->and(collect($report['data']['slo']['indicators'])->contains(
            fn (array $indicator): bool => ($indicator['name'] ?? null) === 'failed_runs_24h_by_target'
                && ($indicator['current'] ?? null) === 1,
        ))->toBeTrue()
        ->and(collect($report['data']['slo']['indicators'])->contains(
            fn (array $indicator): bool => ($indicator['name'] ?? null) === 'verification_failed_runs'
                && ($indicator['current'] ?? null) === 1,
        ))->toBeTrue()
        ->and($report['suggestions'])->toBeArray();

    OperatorCommandTestSupport::resetTime();
});

it('provides driver repository and stanza breakdown groups in json report output', function (): void {
    OperatorCommandTestSupport::freezeTime();

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_full',
        'driver_name' => 'pgbackrest',
        'repository' => 1,
        'stanza' => 'main',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
        'started_at' => now()->subHours(3),
        'finished_at' => now()->subHours(3),
    ]);

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup_full',
        'driver_name' => 'pgbackrest',
        'repository' => 1,
        'stanza' => 'main',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'driver_name' => 'shell',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    Artisan::call('checkpoint:report', ['--limit' => 10, '--format' => 'json']);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['breakdown']['totals'])->toMatchArray([
            'groups' => 2,
            'runs' => 3,
            'failed_runs_24h' => 1,
        ])
        ->and($report['breakdown']['by_target']['driver:pgbackrest|repo:1|stanza:main'])->toMatchArray([
            'driver' => 'pgbackrest',
            'repository' => 1,
            'stanza' => 'main',
            'failure_rate_percent' => 50.0,
            'health_status' => 'fail',
        ])
        ->and($report['breakdown']['by_target']['driver:pgbackrest|repo:1|stanza:main']['runs'])->toMatchArray([
            'total' => 2,
            'succeeded' => 1,
            'failed' => 1,
            'failed_24h' => 1,
        ])
        ->and($report['breakdown']['by_target']['driver:shell|repo:none'])->toMatchArray([
            'driver' => 'shell',
            'repository' => null,
            'stanza' => null,
            'failure_rate_percent' => 0.0,
            'health_status' => 'warn',
        ])
        ->and($report['breakdown']['by_target']['driver:shell|repo:none']['runs'])->toMatchArray([
            'total' => 1,
            'pending' => 1,
            'failed_24h' => 0,
        ]);

    OperatorCommandTestSupport::resetTime();
});
