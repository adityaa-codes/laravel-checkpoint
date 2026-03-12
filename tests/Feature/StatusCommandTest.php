<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
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

    checkpoint_artisan('db-ops:status --limit=2')
        ->expectsTable(
            ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'],
            [
                ['3', 'logical_restore_file', 'Failed', '1', '-', 'failed', '-', '-', '-'],
                ['2', 'pgbackrest_info', 'Succeeded', '0', 'full:20260311-010101F', 'verified', '2026-03-11 11:50:00', '-', '-'],
            ],
        )
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('shows an operator-facing summary of recent checkpoint health signals', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState(includeRunningRun: true);

    checkpoint_artisan('db-ops:status --summary')
        ->expectsTable(
            ['Signal', 'Value'],
            [
                ['Pending runs', '1'],
                ['Running runs', '1'],
                ['Failed runs (24h)', '1'],
                ['Last known good backup', 'full:20260311-010101F at 2026-03-11 11:50:00'],
                ['Latest verified backup', 'full:20260311-010101F at 2026-03-11 11:55:00'],
                ['Latest backup drill', 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00'],
                ['Latest failed drill', 'drill-fail-001 [FAIL] by ops-user at 2026-03-11 09:00:00'],
                ['Backup drill pass rate (30d)', '1/2 (50.0%)'],
                ['Latest restore run', 'logical_restore_file [failed] (nightly.sql) {confirm=token, verified_run=2} at 2026-03-11 11:42:00'],
                ['Latest restore failure', 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00'],
            ],
        )
        ->assertSuccessful();

    OperatorCommandTestSupport::resetTime();
});

it('renders recent runs as machine-readable json', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedRecentRuns();

    Artisan::call('db-ops:status', ['--limit' => 1, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('runs')
        ->and($report['limit'])->toBe(1)
        ->and($report['runs'])->toHaveCount(1)
        ->and($report['runs'][0])->toMatchArray([
            'id' => 2,
            'operation' => 'pgbackrest_info',
            'status' => 'succeeded',
            'exit_code' => 0,
            'backup' => 'full:20260311-010101F',
            'verification_state' => 'verified',
            'restore_target' => null,
            'restore_audit' => null,
            'last_known_good_at' => '2026-03-11 11:50:00',
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('renders summary signals as machine-readable json', function (): void {
    OperatorCommandTestSupport::freezeTime();
    OperatorCommandTestSupport::seedOperatorSummaryState();

    Artisan::call('db-ops:status', ['--summary' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('status')
        ->and($report['mode'])->toBe('summary')
        ->and($report['summary'])->toMatchArray([
            'pending_runs' => 1,
            'running_runs' => 0,
            'failed_runs_24h' => 1,
        ])
        ->and($report['summary']['last_known_good_backup'])->toMatchArray([
            'label' => 'full:20260311-010101F at 2026-03-11 11:50:00',
            'timestamp' => '2026-03-11 11:50:00',
            'operation' => 'pgbackrest_backup_full',
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
        ])
        ->and($report['summary']['latest_restore_run'])->toMatchArray([
            'label' => 'logical_restore_file [failed] (nightly.sql) {confirm=token, verified_run=2} at 2026-03-11 11:42:00',
            'timestamp' => '2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'target' => 'nightly.sql',
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
        ->and($report['summary']['latest_restore_failure'])->toMatchArray([
            'label' => 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00',
            'timestamp' => '2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'target' => 'nightly.sql',
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('fails for unsupported status output formats', function (): void {
    checkpoint_artisan('db-ops:status --format=xml')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();
});
