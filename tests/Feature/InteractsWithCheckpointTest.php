<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

it('initializes and exposes the fake driver binding', function (): void {
    /** @var TestCase $this */
    $driver = $this->fakeDriver();

    expect($driver)->toBeInstanceOf(FakeDriver::class)
        ->and($this->checkpointFakeDriver())->toBe($driver)
        ->and(resolve(FakeDriver::class))->toBe($driver)
        ->and(resolve(BackupDriver::class))->toBe($driver)
        ->and(config('checkpoint.driver'))->toBe('fake');
});

it('asserts queued and failed backup events through the testing trait', function (): void {
    /** @var TestCase $this */
    $this->fakeDriver();

    $queuedRun = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
    ]);

    $failedRun = CommandRun::factory()->make([
        'operation' => 'logical_backup',
    ]);

    event(new BackupQueued($queuedRun));
    event(new BackupFailed($failedRun, 1, 'failed'));

    $this->assertBackupQueued('logical_restore_file', 'nightly.sql')
        ->assertBackupNotQueued('physical_backup')
        ->assertBackupFailed('logical_backup');
});

it('asserts when no backups were queued', function (): void {
    /** @var TestCase $this */
    $this->fakeDriver();

    $this->assertNoBackupsQueued();
});

it('fails when the fake driver has not been initialized', function (): void {
    /** @var TestCase $this */
    expect(fn () => $this->checkpointFakeDriver())
        ->toThrow(AssertionFailedError::class, 'Checkpoint fake driver has not been initialized. Call fakeDriver() first.');
});
