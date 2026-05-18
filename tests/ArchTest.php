<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
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
    ]);

arch('package classes are final by default')
    ->expect('AdityaaCodes\\LaravelCheckpoint')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        'AdityaaCodes\\LaravelCheckpoint\\Actions\\EnqueueCommandRunAction',
        'AdityaaCodes\\LaravelCheckpoint\\Models\\CommandRun',
        'AdityaaCodes\\LaravelCheckpoint\\Models\\VerificationRun',
        'AdityaaCodes\\LaravelCheckpoint\\Models\\RestoreDecisionEvent',
        'AdityaaCodes\\LaravelCheckpoint\\Models\\BackupDrillRun',
    ]);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();
