<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Support\Carbon;

it('orders drill runs by executed_at descending via the latest scope', function (): void {
    $oldRun = BackupDrillRun::factory()->create([
        'executed_at' => Carbon::parse('2026-03-10 08:00:00'),
    ]);

    $newRun = BackupDrillRun::factory()->create([
        'executed_at' => Carbon::parse('2026-03-11 08:00:00'),
    ]);

    expect((new BackupDrillRun)->scopeLatest(BackupDrillRun::query())->pluck('id')->all())
        ->toBe([$newRun->id, $oldRun->id]);
});

it('reports passing and failing drill runs from the overall result', function (): void {
    $passingRun = BackupDrillRun::factory()->passing()->create();
    $failingRun = BackupDrillRun::factory()->failing()->create();

    expect($passingRun->isPassing())->toBeTrue()
        ->and($failingRun->isPassing())->toBeFalse();
});

it('uses factory states for pass and fail drill outcomes', function (): void {
    $passingRun = BackupDrillRun::factory()->passing()->make();
    $failingRun = BackupDrillRun::factory()->failing()->make();

    expect($passingRun->overall_result)->toBe('pass')
        ->and($passingRun->rto_result)->toBe('pass')
        ->and($passingRun->rpo_result)->toBe('pass')
        ->and($failingRun->overall_result)->toBe('fail')
        ->and($failingRun->rto_result)->toBe('fail')
        ->and($failingRun->rpo_result)->toBe('fail');
});
