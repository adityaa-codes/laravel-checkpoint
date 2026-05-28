<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Console\CheckpointCommand;
use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Contracts\Queue\ShouldQueue;

arch('src has no app references')
    ->expect('AdityaaCodes\\LaravelCheckpoint')
    ->not->toUse('App\\');

arch('package code uses strict types')
    ->expect('AdityaaCodes\\LaravelCheckpoint')
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('AdityaaCodes\\LaravelCheckpoint\\Contracts')
    ->toBeInterfaces();

arch('events are readonly')
    ->expect('AdityaaCodes\\LaravelCheckpoint\\Events')
    ->toBeReadonly();

arch('jobs implement should queue')
    ->expect('AdityaaCodes\\LaravelCheckpoint\\Jobs')
    ->toImplement(ShouldQueue::class);

arch('drivers implement backup driver')
    ->expect('AdityaaCodes\\LaravelCheckpoint\\Drivers')
    ->toImplement(BackupDriver::class)
    ->ignoring([
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\Concerns',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\Postgres',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlCommandLineFormatter',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlConfiguration',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlDrillExecutor',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlDriverLogContext',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlMetadataBuilder',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlPitrExecutor',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlProcessBuilder',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlProcessRunner',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlReplicationHandler',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlReplicationMetadataBuilder',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlRestoreExecutor',
        'AdityaaCodes\\LaravelCheckpoint\\Drivers\\MysqlRestoreTargetValidator',
    ]);

arch('package classes are final by default')
    ->expect('AdityaaCodes\\LaravelCheckpoint')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        EnqueueCommandRunAction::class,
        CommandRun::class,
        VerificationRun::class,
        RestoreDecisionEvent::class,
        BackupDrillRun::class,
        CheckpointCommand::class,
    ]);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();
