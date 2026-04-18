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

    $pgbackrest = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    config()->set('checkpoint.drivers.pgdump.class', $pgdump::class);
    config()->set('checkpoint.drivers.pgbackrest.class', $pgbackrest::class);
    app()->instance($pgdump::class, $pgdump);
    app()->instance($pgbackrest::class, $pgbackrest);

    (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'logical_backup',
    ]));

    expect($pgdump->operations)->toBe(['logical_backup'])
        ->and($pgbackrest->operations)->toBeEmpty();
});

it('routes pgbackrest-prefixed operations to the pgbackrest delegate', function (): void {
    $pgdump = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    $pgbackrest = new class implements BackupDriver
    {
        /** @var list<string> */
        public array $operations = [];

        public function execute(CommandRun $run): void
        {
            $this->operations[] = $run->operation;
        }
    };

    config()->set('checkpoint.drivers.pgdump.class', $pgdump::class);
    config()->set('checkpoint.drivers.pgbackrest.class', $pgbackrest::class);
    app()->instance($pgdump::class, $pgdump);
    app()->instance($pgbackrest::class, $pgbackrest);

    (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'pgbackrest_check',
    ]));

    expect($pgbackrest->operations)->toBe(['pgbackrest_check'])
        ->and($pgdump->operations)->toBeEmpty();
});

it('fails for operations that are not mapped by the postgres facade', function (): void {
    expect(fn () => (new PostgresDriver)->execute(CommandRun::factory()->make([
        'operation' => 'backup_drill',
    ])))
        ->toThrow(ConfigurationException::class, 'Unsupported postgres facade operation [backup_drill].');
});
