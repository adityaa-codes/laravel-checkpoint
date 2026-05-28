<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;

it('shows recent command runs in descending order with the requested limit', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedRecentRuns();

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'restore_target' => 'nightly.sql',
        'verification_state' => 'failed',
        'status' => CommandRunStatus::Failed,
        'attempts' => 0,
        'exit_code' => 1,
    ]);

    checkpoint_artisan('checkpoint:status --limit=2')
        ->expectsTable(
            ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'],
            [
                ['3', 'logical_restore_file', 'Failed', '1', '-', 'failed', '-', '-', '-'],
                ['2', 'physical_backup', 'Succeeded', '0', 'full:20260311-010101F', 'verified', '2026-03-11 11:50:00', '-', '-'],
            ],
        )
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('shows an operator-facing summary of recent checkpoint health signals', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState(includeRunningRun: true);

    checkpoint_artisan('checkpoint:status --summary')
        ->expectsTable(
            ['Signal', 'Value'],
            [
                ['Pending runs', '1'],
                ['Running runs', '1'],
                ['Failed runs (24h)', '1'],
                ['Latest failed run', 'logical_restore_file [failed] (exit: 1) at 2026-03-11 11:42:00'],
                ['Latest failed reason', 'Command exited with code 1.'],
                ['Latest failed next action', 'Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.'],
                ['Last known good backup', 'full:20260311-010101F at 2026-03-11 11:50:00'],
                ['Latest verified backup', 'full:20260311-010101F at 2026-03-11 11:55:00'],
                ['Latest backup drill', 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00'],
                ['Latest failed drill', 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00'],
                ['Backup drill pass rate (30d)', '1/2 (50.0%)'],
                ['Backup drill trend', 'Stable (FAIL streak x1, n=2)'],
                ['Backup drill playbook', 'Backup drill pass rate is below threshold'],
                ['Latest restore run', 'logical_restore_file [failed] (nightly.sql) {confirm=token, verified_run=2, post_verify=fail} at 2026-03-11 11:42:00'],
                ['Latest restore failure', 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00'],
            ],
        )
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('renders triage-first brief status output with cause and action', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState(includeRunningRun: true);

    checkpoint_artisan('checkpoint:status --brief')
        ->expectsOutputToContain('Checkpoint triage (brief)')
        ->expectsOutputToContain('Failed (24h): 1 | Pending: 1 | Running: 1')
        ->expectsOutputToContain('Cause: Command exited with code 1.')
        ->expectsOutputToContain('Action now: Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.')
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('renders recent runs as machine-readable json', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedRecentRuns();

    Artisan::call('checkpoint:status', ['--limit' => 1, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('runs')
        ->and($report['limit'])->toBe(1)
        ->and($report['gates'])->toMatchArray([
            'profile' => 'local',
            'profile_source' => 'environment',
            'exit_code' => 0,
        ])
        ->and($report['runs'])->toHaveCount(1)
        ->and($report['runs'][0])->toMatchArray([
            'id' => 2,
            'operation' => 'physical_backup',
            'status' => 'succeeded',
            'exit_code' => 0,
            'backup' => 'full:20260311-010101F',
            'verification_state' => 'verified',
            'restore_target' => null,
            'restore_audit' => null,
            'post_restore_verification' => null,
            'last_known_good_at' => '2026-03-11 11:50:00',
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('renders summary signals as machine-readable json', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:status', ['--summary' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('summary')
        ->and($report['gates'])->toMatchArray([
            'profile' => 'local',
            'profile_source' => 'environment',
            'exit_code' => 0,
        ])
        ->and($report['summary'])->toMatchArray([
            'pending_runs' => 1,
            'running_runs' => 0,
            'failed_runs_24h' => 1,
        ])
        ->and($report['summary']['latest_failed_run'])->toMatchArray([
            'label' => 'logical_restore_file [failed] (exit: 1) at 2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'exit_code' => 1,
            'failure_reason' => 'Command exited with code 1.',
            'next_action' => 'Run php artisan checkpoint:status --full --limit=10 --format=json for full failure context.',
        ])
        ->and($report['summary']['last_known_good_backup'])->toMatchArray([
            'label' => 'full:20260311-010101F at 2026-03-11 11:50:00',
            'timestamp' => '2026-03-11 11:50:00',
            'operation' => 'physical_backup',
        ])
        ->and($report['summary']['latest_backup_drill'])->toMatchArray([
            'label' => 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00',
            'timestamp' => '2026-03-11 09:00:00',
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
        ])
        ->and($report['summary']['latest_failed_backup_drill'])->toMatchArray([
            'label' => 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00',
            'timestamp' => '2026-03-11 09:00:00',
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
        ])
        ->and($report['summary']['backup_drill_pass_rate'])->toMatchArray([
            'label' => '1/2 (50.0%)',
            'window_days' => 30,
            'total' => 2,
            'passing' => 1,
            'pass_rate_percent' => 50.0,
        ])->and($report['summary']['backup_drill_trend'])->toMatchArray([
            'window_days' => 30,
            'sample_size' => 2,
            'latest_result' => 'fail',
            'latest_run_uuid' => 'drill-fail-001',
            'trajectory' => 'stable',
            'status' => 'stable',
        ])->and($report['summary']['backup_drill_trend']['streak'])->toMatchArray([
            'type' => 'fail',
            'length' => 1,
        ])->and($report['summary']['backup_drill_remediation_playbook'])->toMatchArray([
            'signature' => 'drill.pass_rate_below_threshold',
            'severity' => 'warn',
        ])->and($report['summary']['backup_drill_trend']['recent'])->toMatchArray([
            'results' => ['fail', 'pass'],
            'passing' => 1,
            'failing' => 1,
        ])
        ->and($report['summary']['latest_restore_run'])->toMatchArray([
            'label' => 'logical_restore_file [failed] (nightly.sql) {confirm=token, verified_run=2, post_verify=fail} at 2026-03-11 11:42:00',
            'timestamp' => '2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'target' => 'nightly.sql',
        ])
        ->and($report['summary']['latest_restore_run']['post_restore_verification'])->toMatchArray([
            'contract_version' => 1,
            'command_run_id' => 3,
            'operation' => 'logical_restore_file',
            'aggregate_result' => 'fail',
        ])
        ->and($report['summary']['latest_restore_run']['audit'])->toMatchArray([
            'environment' => 'testing',
            'database' => ':memory:',
            'target' => 'nightly.sql',
            'confirmation_required' => true,
            'confirmation_satisfied_via' => 'token',
            'verified_backup_required' => true,
            'verified_signal_run_id' => 2,
        ])
        ->and($report['summary']['latest_restore_run']['audit']['post_restore_verification'])->toMatchArray([
            'aggregate_result' => 'fail',
            'checks_performed' => [
                'restore_audit_recorded',
                'restore_target_recorded',
                'command_exit_code_zero',
                'verified_backup_signal_linkage',
            ],
        ])
        ->and($report['summary']['latest_restore_failure'])->toMatchArray([
            'label' => 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00',
            'timestamp' => '2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'target' => 'nightly.sql',
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('fails for unsupported status output formats', function (): void {
    checkpoint_artisan('checkpoint:status --format=xml')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();
});

it('renders compact agent-friendly status output for runs', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedRecentRuns();

    Artisan::call('checkpoint:status', ['--limit' => 1, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('runs')
        ->and($report['limit'])->toBe(1)
        ->and($report['gates'])->toMatchArray([
            'profile' => 'local',
            'profile_source' => 'environment',
            'exit_code' => 0,
        ])
        ->and($report['runs'])->toHaveCount(1)
        ->and($report['runs'][0])->toMatchArray([
            'id' => 2,
            'operation' => 'physical_backup',
            'status' => 'succeeded',
            'exit_code' => 0,
            'backup' => 'full:20260311-010101F',
            'verification_state' => 'verified',
            'restore_target' => null,
            'restore_audit' => null,
            'post_restore_verification' => null,
            'last_known_good_at' => '2026-03-11 11:50:00',
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('renders compact agent-friendly status output for summary', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('checkpoint:status', ['--summary' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('summary')
        ->and($report['gates'])->toMatchArray([
            'profile' => 'local',
            'profile_source' => 'environment',
            'exit_code' => 0,
        ])
        ->and($report['summary'])->toMatchArray([
            'pending_runs' => 1,
            'running_runs' => 0,
            'failed_runs_24h' => 1,
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('caps machine-readable recent run output to the configured reporting limit', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedRecentRuns();
    config()->set('checkpoint.reporting.max_recent_runs', 1);

    Artisan::call('checkpoint:status', ['--limit' => 50, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['limit'])->toBe(1)
        ->and($report['runs'])->toHaveCount(1);

    OperatorCommandTestSupport::resetTime();
});

it('fails when the configured reporting cap is invalid', function (): void {
    config()->set('checkpoint.reporting.max_recent_runs', 0);

    expect(fn (): int => Artisan::call('checkpoint:status', ['--limit' => 10, '--format' => 'json']))
        ->toThrow(ConfigurationException::class, 'checkpoint.reporting.max_recent_runs must be greater than zero.');
});

it('uses policy profile override for deterministic status gate evaluation', function (): void {
    config()->set('checkpoint.gates.environment_profile_map.testing', 'local');
    config()->set('checkpoint.gates.profiles.staging.evidence.enabled', true);

    $exitCode = Artisan::call('checkpoint:status', ['--summary' => true, '--format' => 'json', '--policy-profile' => 'staging']);
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(11)
        ->and($report)->toBeArray()
        ->and($report['gates'])->toMatchArray([
            'profile' => 'staging',
            'profile_source' => 'override',
            'failed_gate' => 'evidence',
            'exit_code' => 11,
        ]);
});
