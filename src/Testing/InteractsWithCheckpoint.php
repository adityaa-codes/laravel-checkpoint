<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Testing;

use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Services\CheckpointDriverManager;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

/** @api */
trait InteractsWithCheckpoint
{
    protected FakeDriver $checkpointFakeDriver;

    public function fakeDriver(): FakeDriver
    {
        Event::fake([
            BackupQueued::class,
            BackupFailed::class,
        ]);

        $this->checkpointFakeDriver = new FakeDriver;

        app()->instance(FakeDriver::class, $this->checkpointFakeDriver);

        app(CheckpointDriverManager::class)->extend('fake', function () {
            return app(FakeDriver::class);
        });

        config()->set('checkpoint.driver', 'fake');

        return $this->checkpointFakeDriver;
    }

    public function assertBackupQueued(string $operation, ?string $argument = null): static
    {
        Event::assertDispatched(function (BackupQueued $event) use ($operation, $argument): bool {
            if ($event->run->operation !== $operation) {
                return false;
            }

            if ($argument !== null && $event->run->argument_text !== $argument) {
                return false;
            }

            return true;
        });

        return $this;
    }

    public function assertBackupNotQueued(string $operation): static
    {
        Event::assertNotDispatched(BackupQueued::class, fn (BackupQueued $event): bool => $event->run->operation === $operation);

        return $this;
    }

    public function assertNoBackupsQueued(): static
    {
        Event::assertNotDispatched(BackupQueued::class);

        return $this;
    }

    public function assertBackupFailed(string $operation): static
    {
        Event::assertDispatched(fn (BackupFailed $event): bool => $event->run->operation === $operation);

        return $this;
    }

    public function checkpointFakeDriver(): FakeDriver
    {
        Assert::assertTrue(
            isset($this->checkpointFakeDriver),
            'Checkpoint fake driver has not been initialized. Call fakeDriver() first.',
        );

        return $this->checkpointFakeDriver;
    }
}
