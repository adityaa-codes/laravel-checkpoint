<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;

it('persists verification runs when verification metadata transitions to verified', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $run->recordMetadata([
        'verification_state' => 'pending',
        'metadata' => [
            'driver' => 'postgres',
            'summary' => ['ok' => false],
        ],
    ]);

    expect(VerificationRun::query()->count())->toBe(0);

    $run->recordMetadata([
        'verification_state' => 'verified',
        'verified_at' => now(),
        'metadata' => [
            'driver' => 'postgres',
            'summary' => ['ok' => true],
        ],
    ]);

    $persisted = VerificationRun::query()->first();

    expect($persisted)->not->toBeNull()
        ->and($persisted?->command_run_id)->toBe((int) $run->getKey())
        ->and($persisted?->verification_type)->toBe('physical_backup')
        ->and($persisted?->status)->toBe('verified')
        ->and($persisted?->verified_at)->not->toBeNull()
        ->and($persisted?->error_detail)->toBeNull()
        ->and($persisted?->metadata)->toMatchArray([
            'driver' => 'postgres',
            'summary' => ['ok' => true],
        ])
        ->and($run->fresh()?->verificationRuns()->count())->toBe(1);
});

it('persists failed verification runs with error detail', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'command_output' => 'verification mismatch detected',
    ]);

    $run->recordMetadata([
        'verification_state' => 'failed',
        'verified_at' => now(),
        'metadata' => [
            'driver' => 'postgres',
            'summary' => ['error_count' => 1],
        ],
    ]);

    $persisted = VerificationRun::query()->first();

    expect($persisted)->not->toBeNull()
        ->and($persisted?->status)->toBe('failed')
        ->and($persisted?->error_detail)->toBe('verification mismatch detected')
        ->and($persisted?->metadata)->toMatchArray([
            'driver' => 'postgres',
            'summary' => ['error_count' => 1],
        ]);
});
