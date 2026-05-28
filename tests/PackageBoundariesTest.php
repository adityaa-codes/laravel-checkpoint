<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\InstallCommand;
use AdityaaCodes\LaravelCheckpoint\Console\PruneCommand;
use AdityaaCodes\LaravelCheckpoint\Console\StatusCommand;
use AdityaaCodes\LaravelCheckpoint\Console\SweepCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillPassRateAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFreshnessAlarmTriggered;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidOperationException;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpointServiceProvider;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationRequestFactory;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint;

it('keeps package internals final by default with explicit seams', function (): void {
    $finalClasses = [
        SweepCommand::class,
        InstallCommand::class,
        PruneCommand::class,
        StatusCommand::class,
        FakeDriver::class,
        ConfigurationException::class,
        CheckpointArgumentException::class,
        InvalidOperationException::class,
        AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint::class,
        ProcessCommandRunJob::class,
        LaravelCheckpoint::class,
        LaravelCheckpointServiceProvider::class,
        CommandRunCatalog::class,
        RestoreSafetyGuard::class,
        ReplicationRequestFactory::class,
        ReplicationEndpointInputParser::class,
        CommandLineRedactor::class,
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
        BackupFreshnessAlarmTriggered::class,
        BackupDrillFreshnessAlarmTriggered::class,
        BackupDrillPassRateAlarmTriggered::class,
        BackupDrillCompleted::class,
        BackupDrillFailed::class,
        OrphanRunRedispatched::class,
        QueueLagDetected::class,
        LaravelCheckpoint::class,
        RestoreSafetyGuard::class,
    ];

    foreach ($readonlyClasses as $class) {
        expect(new ReflectionClass($class)->isReadOnly())
            ->toBeTrue(sprintf('Expected [%s] to be readonly.', $class));
    }
});

it('marks the intended public package surface as api', function (): void {
    $apiClasses = [
        BackupDriver::class,
        AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint::class,
        LaravelCheckpoint::class,
        CommandRun::class,
        BackupDrillRun::class,
        InteractsWithCheckpoint::class,
    ];

    foreach ($apiClasses as $class) {
        expect(new ReflectionClass($class)->getDocComment())
            ->toContain('@api');
    }
});

it('exposes public properties only for intentional payload seams', function (): void {
    $allowedPublicProperties = [
        BackupQueued::class => ['run'],
        BackupStarted::class => ['run'],
        BackupCompleted::class => ['run', 'exitCode', 'output'],
        BackupFailed::class => ['run', 'exitCode', 'output', 'exception', 'version'],
        BackupFreshnessAlarmTriggered::class => ['run', 'reason', 'ageHours', 'thresholdHours', 'version'],
        BackupDrillFreshnessAlarmTriggered::class => ['run', 'reason', 'ageDays', 'thresholdDays', 'version'],
        BackupDrillPassRateAlarmTriggered::class => ['latestRun', 'passRatePercent', 'passing', 'thresholdPercent', 'total', 'version', 'windowDays'],
        BackupDrillCompleted::class => ['run'],
        BackupDrillFailed::class => ['run', 'exitCode', 'output', 'exception', 'version'],
        OrphanRunRedispatched::class => ['queue', 'run', 'staleAgeMinutes', 'thresholdMinutes', 'version'],
        QueueLagDetected::class => ['oldestStaleAgeMinutes', 'queue', 'staleRunCount', 'staleRunIds', 'staleRunIdsTruncated', 'thresholdMinutes', 'version'],
    ];

    foreach ($allowedPublicProperties as $class => $allowedProperties) {
        $publicProperties = collect((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC))->filter(static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class)->map(static fn (ReflectionProperty $property): string => $property->getName())->all();

        sort($publicProperties);
        sort($allowedProperties);

        expect($publicProperties)
            ->toBe($allowedProperties, sprintf('Unexpected public properties exposed by [%s].', $class));
    }

    expect(new ReflectionClass(FakeDriver::class)->getProperties(ReflectionProperty::IS_PUBLIC))
        ->toBeEmpty();

    $runProperty = new ReflectionProperty(ProcessCommandRunJob::class, 'run');

    expect($runProperty->isPublic())->toBeTrue()
        ->and($runProperty->isReadOnly())->toBeTrue();
});
