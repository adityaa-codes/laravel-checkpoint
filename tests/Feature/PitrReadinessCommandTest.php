<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;

it('reports ready pitr posture in json when baseline and binlog chain are valid', function (): void {
    OperatorCommandTestSupport::freezeTime();

    $baseline = tempnam(sys_get_temp_dir(), 'checkpoint-pitr-baseline-');

    if ($baseline === false) {
        throw new RuntimeException('Unable to allocate PITR baseline file.');
    }

    file_put_contents($baseline, 'baseline');

    $binlogA = tempnam(sys_get_temp_dir(), 'checkpoint-pitr-binlog-a-');
    $binlogB = tempnam(sys_get_temp_dir(), 'checkpoint-pitr-binlog-b-');

    if ($binlogA === false || $binlogB === false) {
        throw new RuntimeException('Unable to allocate PITR binlog files.');
    }

    file_put_contents($binlogA, 'binlog-a');
    file_put_contents($binlogB, 'binlog-b');

    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', [$binlogA, $binlogB]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => $baseline,
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'last_known_good_at' => now()->subHour(),
    ]);

    Artisan::call('db-ops:pitr-readiness', ['2026-03-11 11:30:00', '--format' => 'json']);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['surface'])->toBe('pitr_readiness')
        ->and($payload['version'])->toBe(1)
        ->and($payload['readiness'])->toBe('ready')
        ->and($payload['summary'])->toMatchArray([
            'pass' => 6,
            'fail' => 0,
        ]);

    @unlink($baseline);
    @unlink($binlogA);
    @unlink($binlogB);
    OperatorCommandTestSupport::resetTime();
});

it('reports not-ready pitr posture with actionable failures in agent mode', function (): void {
    OperatorCommandTestSupport::freezeTime();
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', []);

    Artisan::call('db-ops:pitr-readiness', ['2026-03-11 13:30:00', '--agent' => true]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['surface'])->toBe('pitr_readiness')
        ->and($payload['result'])->toBe('failed')
        ->and($payload['data']['readiness'])->toBe('not_ready')
        ->and($payload['suggestions'])->toContain('Run a successful logical backup to establish a last-known-good PITR baseline.')
        ->and($payload['suggestions'])->toContain('Configure checkpoint.drivers.mysql.pitr.binlog_files with the active MySQL binlog chain.');

    OperatorCommandTestSupport::resetTime();
});

it('fails for invalid pitr readiness format and invalid target timestamps', function (): void {
    checkpoint_artisan('db-ops:pitr-readiness --format=xml')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();

    checkpoint_artisan('db-ops:pitr-readiness invalid-target --format=json')
        ->expectsOutputToContain('PITR target must be a valid datetime string.')
        ->assertFailed();
});
