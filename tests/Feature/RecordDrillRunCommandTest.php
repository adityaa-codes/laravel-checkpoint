<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Support\Facades\Event;

it('records a backup drill run and fires the completion event', function (): void {
    Event::fake([BackupDrillCompleted::class]);

    checkpoint_artisan('db-ops:record-drill', [
        '--run-uuid' => '11111111-1111-4111-8111-111111111111',
        '--marker-uuid' => '22222222-2222-4222-8222-222222222222',
        '--marker-email' => 'drill@example.com',
        '--marker-count' => 7,
        '--marker-result' => 'pass',
        '--rto-target-seconds' => 900,
        '--rto-actual-seconds' => 420,
        '--rto-result' => 'pass',
        '--rpo-target-seconds' => 300,
        '--rpo-actual-seconds' => 180,
        '--rpo-result' => 'pass',
        '--overall-result' => 'pass',
        '--executed-by' => 'ci-pipeline',
        '--executed-at' => '2026-03-11T10:30:00+00:00',
    ])
        ->expectsOutput('Recorded backup drill run 11111111-1111-4111-8111-111111111111 (overall: PASS).')
        ->assertSuccessful();

    $run = BackupDrillRun::query()->sole();

    expect($run->run_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($run->marker_email)->toBe('drill@example.com')
        ->and($run->marker_count)->toBe(7)
        ->and($run->overall_result)->toBe('pass')
        ->and($run->executed_by)->toBe('ci-pipeline');

    Event::assertDispatched(fn (BackupDrillCompleted $event): bool => $event->run->is($run));
});

it('fails validation when required drill fields are missing', function (): void {
    Event::fake([BackupDrillCompleted::class]);

    checkpoint_artisan('db-ops:record-drill', [
        '--overall-result' => 'pass',
    ])
        ->expectsOutputToContain('The run uuid field is required.')
        ->assertFailed();

    expect(BackupDrillRun::query()->count())->toBe(0);
    Event::assertNotDispatched(BackupDrillCompleted::class);
});
