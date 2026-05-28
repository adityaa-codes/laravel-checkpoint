<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Support\Facades\Artisan;

it('renders the full operational report table', function (): void {
    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'succeeded',
        'attempts' => 1,
        'exit_code' => 0,
    ]);

    checkpoint_artisan('checkpoint:status --full -v')
        ->expectsOutputToContain('Driver')
        ->expectsOutputToContain('Limit requested')
        ->expectsOutputToContain('Limit applied')
        ->expectsOutputToContain('Recent runs returned')
        ->expectsOutputToContain('Health OK')
        ->assertSuccessful();
});

it('renders the brief operational report', function (): void {
    checkpoint_artisan('checkpoint:status --full --brief')
        ->expectsOutputToContain('Checkpoint report (brief)')
        ->expectsOutputToContain('Failed runs (24h):')
        ->expectsOutputToContain('P0:')
        ->expectsOutputToContain('Action now:')
        ->expectsOutputToContain('Deep dive:')
        ->assertSuccessful();
});

it('renders operational report json output', function (): void {
    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'succeeded',
        'attempts' => 1,
        'exit_code' => 0,
    ]);

    Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report['mode'])->toBe('full')
        ->and($report['driver'])->toBeString()
        ->and($report['limit_requested'])->toBeInt()
        ->and($report['limit'])->toBeInt()
        ->and($report['recent_runs'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['health'])->toBeArray();
});

it('renders operational report compact json output', function (): void {
    Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report');
});

it('renders operational report agent output', function (): void {
    Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(2)
        ->and($report['surface'])->toBe('report')
        ->and($report['driver'])->toBeString()
        ->and($report['recent_runs'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['health'])->toBeArray();
});

it('respects the limit option for recent runs', function (): void {
    for ($i = 0; $i < 5; $i++) {
        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'status' => 'succeeded',
            'attempts' => 1,
            'exit_code' => 0,
        ]);
    }

    Artisan::call('checkpoint:status', ['--full' => true, '--limit' => '2', '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['limit_requested'])->toBe(2)
        ->and($report['limit'])->toBe(2)
        ->and(count($report['recent_runs']))->toBeLessThanOrEqual(2);
});

it('includes verification summary in report', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'succeeded',
        'attempts' => 1,
        'exit_code' => 0,
    ]);

    VerificationRun::query()->create([
        'command_run_id' => $run->id,
        'verification_type' => 'logical_backup',
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['verification'])->toBeArray()
        ->and($report['verification']['total_runs'])->toBeGreaterThanOrEqual(1)
        ->and($report['verification']['verified_runs'])->toBeGreaterThanOrEqual(1);
});

it('returns gate decision in report output', function (): void {
    Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['gates'])->toBeArray()
        ->and($report['gates'])->toHaveKeys(['profile', 'profile_source', 'verdict', 'failed_gate', 'exit_code']);
});

it('handles report execution errors gracefully', function (): void {
    config()->set('checkpoint.driver', 'postgres');
    config()->set('database.connections.pgsql.dump.dump_binary_path', '/nonexistent-binary-path');

    $exitCode = Artisan::call('checkpoint:status', ['--full' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['health']['ok'])->toBeFalse();
});
