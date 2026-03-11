<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint as LaravelCheckpointFacade;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
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

        public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
        {
            expect($operation)->toBe('logical_backup');
            expect($argument)->toBeNull();
            expect($requestedBy)->toBeNull();

            return $this->run;
        }
    };

    $checkpoint = new LaravelCheckpoint($action);

    expect($checkpoint->execute('logical_backup'))->toBe($expectedRun);
});

it('delegates execution through the facade', function (): void {
    $expectedRun = CommandRun::factory()->make([
        'operation' => 'pgbackrest_info',
    ]);

    $action = new class($expectedRun) extends EnqueueCommandRunAction
    {
        public function __construct(
            private readonly CommandRun $run,
        ) {}

        public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
        {
            expect($operation)->toBe('pgbackrest_info');
            expect($argument)->toBeNull();
            expect($requestedBy)->toBeNull();

            return $this->run;
        }
    };

    app()->instance(LaravelCheckpoint::class, new LaravelCheckpoint($action));

    Facade::clearResolvedInstance(LaravelCheckpoint::class);

    expect(LaravelCheckpointFacade::execute('pgbackrest_info'))->toBe($expectedRun);
});

it('allows viewing command runs and creating new ones', function (): void {
    $policy = new CommandRunPolicy;
    $user = new stdClass;
    $run = CommandRun::factory()->make();

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $run))->toBeTrue()
        ->and($policy->create($user))->toBeTrue();
});

it('allows viewing backup drill runs but forbids mutations', function (): void {
    $policy = new BackupDrillRunPolicy;
    $user = new stdClass;
    $run = BackupDrillRun::factory()->make();

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $run))->toBeTrue()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $run))->toBeFalse()
        ->and($policy->delete($user, $run))->toBeFalse();
});
