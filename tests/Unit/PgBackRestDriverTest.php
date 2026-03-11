<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

it('builds typed pgbackrest backup commands from structured config', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.stanza', 'main');
    config()->set('checkpoint.drivers.pgbackrest.repo', 2);
    config()->set('checkpoint.drivers.pgbackrest.process_max', 4);
    config()->set('checkpoint.drivers.pgbackrest.resume', true);
    config()->set('checkpoint.drivers.pgbackrest.start_fast', true);
    config()->set('checkpoint.drivers.pgbackrest.backup_standby', true);
    config()->set('checkpoint.drivers.pgbackrest.checksum_page', true);
    config()->set('checkpoint.drivers.pgbackrest.extra_args.backup', ['--buffer-size=4MiB']);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_backup_full',
    ]);

    $process = buildPgBackRestProcess(new PgBackRestDriver, $run);

    expect($process)->toBeInstanceOf(Process::class)
        ->and($process->getCommandLine())->toContain('pgbackrest')
        ->and($process->getCommandLine())->toContain('backup')
        ->and($process->getCommandLine())->toContain('--type=full')
        ->and($process->getCommandLine())->toContain('--stanza=main')
        ->and($process->getCommandLine())->toContain('--repo=2')
        ->and($process->getCommandLine())->toContain('--process-max=4')
        ->and($process->getCommandLine())->toContain('--resume')
        ->and($process->getCommandLine())->toContain('--start-fast')
        ->and($process->getCommandLine())->toContain('--backup-standby')
        ->and($process->getCommandLine())->toContain('--checksum-page')
        ->and($process->getCommandLine())->toContain('--buffer-size=4MiB');
});

it('adds restore options from structured config and the optional backup set argument', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.stanza', 'archive');
    config()->set('checkpoint.drivers.pgbackrest.repo', 1);
    config()->set('checkpoint.drivers.pgbackrest.delta', true);
    config()->set('checkpoint.drivers.pgbackrest.extra_args.restore', ['--target-action=promote']);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    $process = buildPgBackRestProcess(new PgBackRestDriver, $run);

    expect($process->getCommandLine())->toContain('restore')
        ->and($process->getCommandLine())->toContain('--stanza=archive')
        ->and($process->getCommandLine())->toContain('--repo=1')
        ->and($process->getCommandLine())->toContain('--delta')
        ->and($process->getCommandLine())->toContain('--set=20260312-010101F')
        ->and($process->getCommandLine())->toContain('--target-action=promote');
});

it('rejects an empty pgbackrest binary configuration', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', '');

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_info',
    ]);

    expect(fn (): Process => buildPgBackRestProcess(new PgBackRestDriver, $run))
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.binary must be a non-empty string.');
});

it('rejects unsupported pgbackrest operations', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'logical_backup',
    ]);

    expect(fn (): Process => buildPgBackRestProcess(new PgBackRestDriver, $run))
        ->toThrow(ConfigurationException::class, 'Unsupported pgBackRest operation [logical_backup].');
});

function buildPgBackRestProcess(PgBackRestDriver $driver, CommandRun $run): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run);

    return $process;
}
