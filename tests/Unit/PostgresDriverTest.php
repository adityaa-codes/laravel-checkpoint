<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

it('routes logical operations to the pgdump delegate', function (): void {
    $pgdump = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    $pgbasebackup = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    config()->set('checkpoint.drivers.pgdump.class', $pgdump::class);
    config()->set('checkpoint.drivers.pgbasebackup.class', $pgbasebackup::class);
    app()->instance($pgdump::class, $pgdump);
    app()->instance($pgbasebackup::class, $pgbasebackup);

    (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'logical_backup',
    ]));

    expect($pgdump->operations)->toBe(['logical_backup'])
        ->and($pgbasebackup->operations)->toBeEmpty();
});

it('routes pgbasebackup-prefixed operations to the pgbasebackup delegate', function (): void {
    $pgdump = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    $pgbasebackup = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    config()->set('checkpoint.drivers.pgdump.class', $pgdump::class);
    config()->set('checkpoint.drivers.pgbasebackup.class', $pgbasebackup::class);
    app()->instance($pgdump::class, $pgdump);
    app()->instance($pgbasebackup::class, $pgbasebackup);

    (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'physical_backup',
    ]));

    expect($pgbasebackup->operations)->toBe(['physical_backup'])
        ->and($pgdump->operations)->toBeEmpty();
});

it('fails for operations that are not mapped by the postgres facade', function (): void {
    expect(fn () => (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'unknown_operation',
    ])))
        ->toThrow(ConfigurationException::class, 'Unsupported postgres facade operation [unknown_operation].');
});
