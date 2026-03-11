<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\DoctorCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueCommand;
use AdityaaCodes\LaravelCheckpoint\Console\EnqueueLogicalBackupCommand;
use AdityaaCodes\LaravelCheckpoint\Console\HealthCheckCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecordDrillRunCommand;
use AdityaaCodes\LaravelCheckpoint\Console\RecoverOrphansCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidOperationException;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpointServiceProvider;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;

it('keeps package internals final by default with explicit seams', function (): void {
    $finalClasses = [
        DoctorCommand::class,
        EnqueueCommand::class,
        EnqueueLogicalBackupCommand::class,
        HealthCheckCommand::class,
        PruneCommand::class,
        RecordDrillRunCommand::class,
        RecoverOrphansCommand::class,
        StatusCommand::class,
        FakeDriver::class,
        ShellCommandDriver::class,
        ConfigurationException::class,
        InvalidArgumentException::class,
        InvalidOperationException::class,
        AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint::class,
        ProcessCommandRunJob::class,
        LaravelCheckpoint::class,
        LaravelCheckpointServiceProvider::class,
        BackupDrillRunPolicy::class,
        CommandRunPolicy::class,
        CommandRunCatalog::class,
        ConfigValidator::class,
    ];

    foreach ($finalClasses as $class) {
        expect(new ReflectionClass($class)->isFinal())
            ->toBeTrue(sprintf('Expected [%s] to be final.', $class));
    }

    expect(new ReflectionClass(EnqueueCommandRunAction::class)->isFinal())
        ->toBeFalse('EnqueueCommandRunAction is an intentional seam for command-level replacement tests.');
});

it('keeps immutable payload and service objects readonly where appropriate', function (): void {
    $readonlyClasses = [
        BackupQueued::class,
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
        BackupDrillCompleted::class,
        LaravelCheckpoint::class,
        ConfigValidator::class,
    ];

    foreach ($readonlyClasses as $class) {
        expect(new ReflectionClass($class)->isReadOnly())
            ->toBeTrue(sprintf('Expected [%s] to be readonly.', $class));
    }
});
