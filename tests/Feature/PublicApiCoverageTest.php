<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint as LaravelCheckpointFacade;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

it('resolves the public api wrapper as a singleton', function (): void {
    $first = resolve(LaravelCheckpoint::class);
    $second = resolve(LaravelCheckpoint::class);

    expect($first)
        ->toBeInstanceOf(LaravelCheckpoint::class)
        ->and($second)
        ->toBe($first);
});

it('delegates execution through the public api wrapper', function (): void {
    $expectedRun = CommandRun::factory()->make([
        'operation' => 'logical_backup',
    ]);

    $action = new class($expectedRun) extends EnqueueCommandRunAction
    {
        public function __construct(
            private readonly CommandRun $run,
        ) {}

        public function execute(CheckpointOperation $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
        {
            expect($operation)->toBe(CheckpointOperation::Backup);
            expect($argument)->toBeNull();
            expect($requestedBy)->toBeNull();

            return $this->run;
        }
    };

    $checkpoint = new LaravelCheckpoint($action);

    expect($checkpoint->execute(CheckpointOperation::Backup))->toBe($expectedRun);
});

it('delegates execution through the facade', function (): void {
    $expectedRun = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
    ]);

    $action = new class($expectedRun) extends EnqueueCommandRunAction
    {
        public function __construct(
            private readonly CommandRun $run,
        ) {}

        public function execute(CheckpointOperation $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
        {
            expect($operation)->toBe(CheckpointOperation::RestoreFile);
            expect($argument)->toBeNull();
            expect($requestedBy)->toBeNull();

            return $this->run;
        }
    };

    app()->instance(LaravelCheckpoint::class, new LaravelCheckpoint($action));

    Facade::clearResolvedInstance(LaravelCheckpoint::class);

    expect(LaravelCheckpointFacade::execute(CheckpointOperation::RestoreFile))->toBe($expectedRun);
});

it('exposes verification runs as a public model surface', function (): void {
    $verificationRun = VerificationRun::factory()->make();

    expect($verificationRun)->toBeInstanceOf(VerificationRun::class);
});
