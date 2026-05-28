<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('renders pitr readiness table output', function (): void {
    $exitCode = Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true]);

    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Check')
        ->and($output)->toContain('Status')
        ->and($output)->toContain('PITR Readiness');
});

it('renders pitr readiness json output', function (): void {
    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('pitr_readiness')
        ->and($report['readiness'])->toBeString()
        ->and($report['target'])->toBeString()
        ->and($report['checks'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['summary'])->toHaveKeys(['pass', 'fail']);
});

it('renders pitr readiness compact json output', function (): void {
    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('pitr_readiness')
        ->and($report['driver'])->toBeString()
        ->and($report['generated_at'])->toBeString();
});

it('renders pitr readiness agent output', function (): void {
    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('pitr_readiness')
        ->and($report['readiness'])->toBeString()
        ->and($report['checks'])->toBeArray()
        ->and($report['summary'])->toBeArray();
});

it('evaluates pitr readiness for a specific target', function (): void {
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', []);

    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--target' => '2026-01-01 00:00:00', '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['target'])->toBe('2026-01-01 00:00:00');
});

it('reports pitr not ready when baseline is missing', function (): void {
    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['readiness'])->toBe('not_ready')
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'baseline.last_known_good'
                && $check['status'] === 'fail',
        ))->toBeTrue();
});

it('reports pitr ready when all prerequisites are met', function (): void {
    $artifactPath = storage_path('app/checkpoint/test-baseline.sql');
    File::ensureDirectoryExists(File::dirname($artifactPath));
    File::put($artifactPath, '-- baseline');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => 'succeeded',
        'attempts' => 1,
        'exit_code' => 0,
        'artifact_path' => $artifactPath,
        'last_known_good_at' => now()->subDays(2),
    ]);

    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', []);

    Artisan::call('checkpoint:restore', ['--pitr-dry-run' => true, '--target' => now()->subDay()->toDateTimeString(), '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    File::delete($artifactPath);

    expect($report)->toBeArray()
        ->and($report['readiness'])->toBe('not_ready')
        ->and(collect($report['checks'])->contains(
            fn (array $check): bool => $check['code'] === 'baseline.last_known_good'
                && $check['status'] === 'pass',
        ))->toBeTrue();
});
