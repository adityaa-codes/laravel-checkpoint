<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Testing;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
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
        app()->instance(BackupDriver::class, $this->checkpointFakeDriver);

        return $this->checkpointFakeDriver;
    }

    public function assertBackupQueued(CheckpointOperation|string $operation, ?string $argument = null): static
    {
        $operationValue = $operation instanceof CheckpointOperation ? $operation->value : $operation;

        Event::assertDispatched(function (BackupQueued $event) use ($operationValue, $argument): bool {
            if ($event->run->operation !== $operationValue) {
                return false;
            }

            if ($argument !== null && $event->run->argument_text !== $argument) {
                return false;
            }

            return true;
        });

        return $this;
    }

    public function assertBackupNotQueued(CheckpointOperation|string $operation): static
    {
        $operationValue = $operation instanceof CheckpointOperation ? $operation->value : $operation;

        Event::assertNotDispatched(BackupQueued::class, fn (BackupQueued $event): bool => $event->run->operation === $operationValue);

        return $this;
    }

    public function assertNoBackupsQueued(): static
    {
        Event::assertNotDispatched(BackupQueued::class);

        return $this;
    }

    public function assertBackupFailed(CheckpointOperation|string $operation): static
    {
        $operationValue = $operation instanceof CheckpointOperation ? $operation->value : $operation;

        Event::assertDispatched(fn (BackupFailed $event): bool => $event->run->operation === $operationValue);

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
