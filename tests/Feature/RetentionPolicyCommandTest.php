<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

it('evaluates retention policy in dry-run mode with tiered windows', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    config()->set('checkpoint.retention.enabled', true);
    config()->set('checkpoint.retention.default_days', 90);
    config()->set('checkpoint.retention.failed_days', 365);
    config()->set('checkpoint.retention.tiers', ['hot' => 14, 'warm' => 60, 'cold' => 180]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subDays(20),
        'updated_at' => now()->subDays(20),
        'metadata' => ['storage' => ['class' => 'hot']],
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subDays(70),
        'updated_at' => now()->subDays(70),
        'metadata' => ['storage' => ['class' => 'warm']],
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'created_at' => now()->subDays(500),
        'updated_at' => now()->subDays(500),
    ]);

    Artisan::call('checkpoint:retention-policy', ['--format' => 'json', '--dry-run' => true, '--limit' => 100]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['surface'])->toBe('retention_policy')
        ->and($payload['version'])->toBe(1)
        ->and($payload['mode'])->toBe('dry_run')
        ->and($payload['retention_enabled'])->toBeTrue()
        ->and($payload['totals']['eligible'])->toBe(3)
        ->and($payload['totals']['deleted'])->toBe(0)
        ->and($payload['by_policy'])->toHaveKeys(['tier:hot', 'tier:warm', 'failed']);

    Date::setTestNow();
});

it('applies retention policy and prunes matching rows and externalized output', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    Storage::fake('local');

    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.filesystem.disk', 'local');
    config()->set('checkpoint.output.filesystem.path_prefix', 'checkpoint/retention-policy');
    config()->set('checkpoint.retention.enabled', true);
    config()->set('checkpoint.retention.default_days', 30);
    config()->set('checkpoint.retention.failed_days', 365);
    config()->set('checkpoint.retention.tiers', ['hot' => 14, 'warm' => 60, 'cold' => 180]);

    $prunable = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subDays(45),
        'updated_at' => now()->subDays(45),
        'command_output' => 'preview',
        'metadata' => [
            'output_storage' => [
                'driver' => 'filesystem',
                'externalized' => true,
                'disk' => 'local',
                'path' => 'checkpoint/retention-policy/command-run-1.log',
            ],
        ],
    ]);

    Storage::disk('local')->put('checkpoint/retention-policy/command-run-1.log', 'artifact');

    $retained = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    Artisan::call('checkpoint:retention-policy', ['--format' => 'json', '--apply' => true, '--limit' => 100]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->and($payload['mode'])->toBe('apply')
        ->and($payload['totals']['deleted'])->toBe(1)
        ->and(CommandRun::query()->find($prunable->getKey()))->toBeNull()
        ->and(CommandRun::query()->find($retained->getKey()))->not->toBeNull();

    Storage::disk('local')->assertMissing('checkpoint/retention-policy/command-run-1.log');

    Date::setTestNow();
});

it('fails for invalid retention policy options', function (): void {
    checkpoint_artisan('checkpoint:retention-policy --format=csv')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();

    checkpoint_artisan('checkpoint:retention-policy --dry-run --apply')
        ->expectsOutput('Use either --dry-run or --apply, not both.')
        ->assertFailed();
});
