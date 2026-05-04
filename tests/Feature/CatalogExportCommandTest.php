<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use AdityaaCodes\LaravelCheckpoint\Tests\Support\OperatorCommandTestSupport;
use Illuminate\Support\Facades\Artisan;

it('exports backup catalog in machine-readable json', function (): void {
    OperatorCommandTestSupport::freezeTime();

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'driver_name' => 'pgbasebackup',
        'repository' => 2,
        'stanza' => 'archive',
        'backup_type' => 'full',
        'backup_label' => '20260311-010101F',
        'artifact_path' => '/var/backups/full-20260311.tar',
        'backup_size_bytes' => 1048576,
        'verification_state' => 'verified',
        'verified_at' => now()->subMinutes(5),
        'last_known_good_at' => now()->subMinutes(4),
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'metadata' => [
            'driver' => 'pgbasebackup',
            'storage' => ['class' => 'warm'],
            'flags' => ['nightly'],
        ],
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(9),
    ]);

    VerificationRun::query()->create([
        'command_run_id' => 1,
        'verification_type' => 'physical_backup',
        'status' => 'verified',
        'verified_at' => now()->subMinutes(5),
        'metadata' => ['driver' => 'pgbasebackup'],
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'backup_label' => 'nightly-002',
        'artifact_path' => '/var/backups/nightly-002.sql',
        'backup_size_bytes' => 256000,
        'verification_state' => 'not_applicable',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'metadata' => [
            'storage' => ['class' => 'hot'],
        ],
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinutes(2),
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
    ]);

    Artisan::call('checkpoint:catalog-export', ['--format' => 'json', '--limit' => 10]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['version'])->toBe(1)
        ->and($payload['surface'])->toBe('catalog_export')
        ->and($payload['format'])->toBe('json')
        ->and($payload['limit_requested'])->toBe(10)
        ->and($payload['limit'])->toBe(10)
        ->and($payload['count'])->toBe(2)
        ->and($payload['rows'])->toHaveCount(2)
        ->and($payload['rows'][0])->toMatchArray([
            'command_run_id' => 2,
            'operation' => 'logical_backup',
            'driver' => 'shell',
            'repository' => null,
            'stanza' => null,
            'type' => 'logical_export',
            'label' => 'nightly-002',
            'path' => '/var/backups/nightly-002.sql',
            'size_bytes' => 256000,
            'status' => 'succeeded',
            'verification_state' => 'not_applicable',
            'latest_verification' => null,
        ])
        ->and($payload['rows'][1])->toMatchArray([
            'command_run_id' => 1,
            'operation' => 'physical_backup',
            'driver' => 'pgbasebackup',
            'repository' => 2,
            'stanza' => 'archive',
            'type' => 'full',
            'label' => '20260311-010101F',
            'path' => '/var/backups/full-20260311.tar',
            'size_bytes' => 1048576,
            'status' => 'succeeded',
            'verification_state' => 'verified',
        ])
        ->and($payload['rows'][1]['latest_verification'])->toMatchArray([
            'id' => 1,
            'verification_type' => 'physical_backup',
            'status' => 'verified',
            'verified_at' => '2026-03-11 11:55:00',
            'error_detail' => null,
        ]);

    OperatorCommandTestSupport::resetTime();
});

it('exports backup catalog as deterministic csv rows', function (): void {
    OperatorCommandTestSupport::freezeTime();

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'driver_name' => 'pgbasebackup',
        'repository' => 1,
        'stanza' => 'main',
        'backup_type' => 'diff',
        'backup_label' => '20260311-020202D',
        'artifact_path' => '/var/backups/diff-20260311.tar',
        'backup_size_bytes' => 1234,
        'verification_state' => 'failed',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
        'metadata' => ['reason' => 'checksum_mismatch'],
        'created_at' => now()->subMinutes(1),
        'updated_at' => now()->subMinutes(1),
    ]);

    Artisan::call('checkpoint:catalog-export', ['--format' => 'csv', '--limit' => 1]);

    $lines = array_values(array_filter(explode(PHP_EOL, trim(Artisan::output())), static fn (string $line): bool => $line !== ''));

    expect($lines)->toHaveCount(2);

    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);

    expect($header)->toBe([
        'command_run_id',
        'operation',
        'driver',
        'repository',
        'stanza',
        'type',
        'label',
        'path',
        'size_bytes',
        'status',
        'verification_state',
        'created_at',
        'started_at',
        'finished_at',
        'verified_at',
        'last_known_good_at',
        'latest_verification_json',
        'metadata_json',
    ])->and($row[0])->toBe('1')
        ->and($row[1])->toBe('physical_backup')
        ->and($row[2])->toBe('pgbasebackup')
        ->and($row[5])->toBe('diff')
        ->and($row[9])->toBe('failed');

    OperatorCommandTestSupport::resetTime();
});

it('filters catalog exports by driver repository stanza and window', function (): void {
    OperatorCommandTestSupport::freezeTime();

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'driver_name' => 'pgbasebackup',
        'repository' => 2,
        'stanza' => 'archive',
        'backup_type' => 'full',
        'backup_label' => 'keep-1',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'driver_name' => 'pgbasebackup',
        'repository' => 1,
        'stanza' => 'main',
        'backup_type' => 'full',
        'backup_label' => 'skip-1',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'driver_name' => 'pgbasebackup',
        'repository' => 2,
        'stanza' => 'archive',
        'backup_type' => 'full',
        'backup_label' => 'skip-2',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subHours(30),
        'updated_at' => now()->subHours(30),
    ]);

    Artisan::call('checkpoint:catalog-export', [
        '--format' => 'json',
        '--driver' => 'pgbasebackup',
        '--repository' => '2',
        '--stanza' => 'archive',
        '--window' => '24',
        '--limit' => 10,
    ]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['count'])->toBe(1)
        ->and($payload['filters'])->toMatchArray([
            'driver' => 'pgbasebackup',
            'repository' => 2,
            'stanza' => 'archive',
            'window_hours' => 24,
        ])
        ->and($payload['rows'][0]['label'])->toBe('keep-1');

    OperatorCommandTestSupport::resetTime();
});

it('fails for invalid catalog export options', function (): void {
    checkpoint_artisan('checkpoint:catalog-export --format=xml')
        ->expectsOutput('The --format option must be json or csv.')
        ->assertFailed();

    checkpoint_artisan('checkpoint:catalog-export --repository=abc')
        ->expectsOutput('The --repository option must be an integer or "none".')
        ->assertFailed();

    checkpoint_artisan('checkpoint:catalog-export --window=0')
        ->expectsOutput('The --window option must be a positive integer.')
        ->assertFailed();
});
