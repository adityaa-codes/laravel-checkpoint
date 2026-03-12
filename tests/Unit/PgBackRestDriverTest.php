<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

it('builds typed pgbackrest backup commands from structured config', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.stanza', 'main');
    config()->set('checkpoint.drivers.pgbackrest.repo', 2);
    config()->set('checkpoint.drivers.pgbackrest.repositories', [
        1 => [
            'type' => 'posix',
            'path' => '/var/lib/pgbackrest/repo1',
            's3' => [
                'bucket' => null,
                'endpoint' => null,
                'region' => null,
                'key' => null,
                'secret' => null,
                'uri_style' => 'host',
            ],
            'tls' => [
                'verify' => true,
                'ca_file' => null,
            ],
            'encryption' => [
                'enabled' => false,
                'cipher_type' => 'aes-256-cbc',
                'passphrase' => null,
            ],
        ],
        2 => [
            'type' => 's3',
            'path' => null,
            's3' => [
                'bucket' => 'checkpoint-backups',
                'endpoint' => 's3.example.com',
                'region' => 'ap-south-1',
                'key' => 'repo-key',
                'secret' => 'repo-secret',
                'uri_style' => 'path',
            ],
            'tls' => [
                'verify' => true,
                'ca_file' => '/etc/ssl/checkpoint.pem',
            ],
            'encryption' => [
                'enabled' => true,
                'cipher_type' => 'aes-256-cbc',
                'passphrase' => 'cipher-passphrase',
            ],
        ],
    ]);
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
        ->and($process->getCommandLine())->toContain('--repo2-type=s3')
        ->and($process->getCommandLine())->toContain('--repo2-s3-bucket=checkpoint-backups')
        ->and($process->getCommandLine())->toContain('--repo2-s3-endpoint=s3.example.com')
        ->and($process->getCommandLine())->toContain('--repo2-s3-region=ap-south-1')
        ->and($process->getCommandLine())->toContain('--repo2-s3-uri-style=path')
        ->and($process->getCommandLine())->toContain('--repo2-storage-verify-tls=y')
        ->and($process->getCommandLine())->toContain('--repo2-storage-ca-file=/etc/ssl/checkpoint.pem')
        ->and($process->getCommandLine())->toContain('--repo2-cipher-type=aes-256-cbc')
        ->and($process->getCommandLine())->toContain('--repo2-cipher-pass=cipher-passphrase')
        ->and($process->getCommandLine())->toContain('--process-max=4')
        ->and($process->getCommandLine())->toContain('--resume')
        ->and($process->getCommandLine())->toContain('--start-fast')
        ->and($process->getCommandLine())->toContain('--backup-standby')
        ->and($process->getCommandLine())->toContain('--checksum-page')
        ->and($process->getCommandLine())->toContain('--buffer-size=4MiB');
});

it('requests json output for pgbackrest info operations', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_info',
    ]);

    $process = buildPgBackRestProcess(new PgBackRestDriver, $run);

    expect($process->getCommandLine())
        ->toContain('info')
        ->and($process->getCommandLine())->toContain('--output=json');
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

it('stores a structured summary for pgbackrest info runs', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    config()->set('checkpoint.drivers.pgbackrest.binary', fakePgBackRestScript(<<<'SH'
#!/bin/sh
printf '%s' '[{"name":"main","status":{"code":0,"message":"ok"},"backup":[{"label":"20260312-010101F","type":"full","timestamp":{"stop":1710200000},"info":{"repository":{"size":1048576}}}]}]'
SH));

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgBackRestDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->command_line)->toContain('--output=json')
        ->and($run->backup_label)->toBe('20260312-010101F')
        ->and($run->backup_type)->toBe('full')
        ->and($run->backup_size_bytes)->toBe(1048576)
        ->and($run->last_known_good_at?->timestamp)->toBe(1710200000)
        ->and($run->metadata)->toBeArray()
        ->and($run->command_output)->toContain('[checkpoint-summary]')
        ->and($run->command_output)->toContain('"format": "pgbackrest-info-v1"')
        ->and($run->command_output)->toContain('"label": "20260312-010101F"')
        ->and($run->command_output)->toContain('[raw-output]');

    Event::assertDispatched(BackupCompleted::class);
    Event::assertNotDispatched(BackupFailed::class);
});

it('redacts repository secrets from persisted command lines and logs', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    config()->set('checkpoint.drivers.pgbackrest.repositories', [
        1 => [
            'type' => 's3',
            'path' => null,
            's3' => [
                'bucket' => 'checkpoint-backups',
                'endpoint' => 's3.example.com',
                'region' => 'ap-south-1',
                'key' => 'AKIA-SECRET-KEY',
                'secret' => 'super-secret-token',
                'uri_style' => 'host',
            ],
            'tls' => [
                'verify' => true,
                'ca_file' => null,
            ],
            'encryption' => [
                'enabled' => true,
                'cipher_type' => 'aes-256-cbc',
                'passphrase' => 'repo-passphrase',
            ],
        ],
    ]);
    config()->set('checkpoint.drivers.pgbackrest.binary', fakePgBackRestScript(<<<'SH'
#!/bin/sh
printf '%s' '[{"name":"main","status":{"code":0,"message":"ok"},"backup":[]}]'
SH));

    $logger = Mockery::mock(LoggerInterface::class);

    $logger->shouldReceive('info')
        ->once()
        ->with('Starting pgBackRest operation', Mockery::on(function (array $context): bool {
            return str_contains((string) $context['command_line'], '--repo1-s3-key=[REDACTED]')
                && str_contains((string) $context['command_line'], '--repo1-s3-key-secret=[REDACTED]')
                && str_contains((string) $context['command_line'], '--repo1-cipher-pass=[REDACTED]')
                && ! str_contains((string) $context['command_line'], 'AKIA-SECRET-KEY')
                && ! str_contains((string) $context['command_line'], 'super-secret-token')
                && ! str_contains((string) $context['command_line'], 'repo-passphrase');
        }));
    $logger->shouldReceive('info')
        ->once()
        ->with('Completed pgBackRest operation', Mockery::type('array'));
    Log::shouldReceive('channel')
        ->twice()
        ->andReturn($logger);

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgBackRestDriver)->execute($run);

    $run->refresh();

    expect($run->command_line)->toContain('--repo1-s3-key=[REDACTED]')
        ->and($run->command_line)->toContain('--repo1-s3-key-secret=[REDACTED]')
        ->and($run->command_line)->toContain('--repo1-cipher-pass=[REDACTED]')
        ->and($run->command_line)->not->toContain('AKIA-SECRET-KEY')
        ->and($run->command_line)->not->toContain('super-secret-token')
        ->and($run->command_line)->not->toContain('repo-passphrase');
});

it('stores a structured summary when pgbackrest check fails', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    config()->set('checkpoint.drivers.pgbackrest.binary', fakePgBackRestScript(<<<'SH'
#!/bin/sh
echo 'P00   INFO: check command begin 2.57'
echo 'WARN: stanza main archive command exceeded 60 seconds'
echo 'ERROR: [082]: WAL segment 000000010000000000000001 was not archived before the 60000ms timeout'
exit 25
SH));

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    (new PgBackRestDriver)->execute($run);

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(25)
        ->and($run->verification_state)->toBe('failed')
        ->and($run->verified_at)->not->toBeNull()
        ->and($run->metadata)->toBeArray()
        ->and($run->command_output)->toContain('"format": "pgbackrest-check-v1"')
        ->and($run->command_output)->toContain('"warning_count": 1')
        ->and($run->command_output)->toContain('"error_count": 1')
        ->and($run->command_output)->toContain('"stanza": "main"')
        ->and($run->command_output)->toContain('WAL segment 000000010000000000000001');

    Event::assertDispatched(BackupFailed::class);
    Event::assertNotDispatched(BackupCompleted::class);
});

it('blocks pgbackrest restore execution in disallowed environments', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.restore.allowed_environments', ['staging']);
    config()->set('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => (new PgBackRestDriver)->execute($run))
        ->toThrow(ConfigurationException::class, 'Restore operations are blocked in environment [production].');
});

function buildPgBackRestProcess(PgBackRestDriver $driver, CommandRun $run): Process
{
    $method = new ReflectionMethod($driver, 'buildProcess');

    /** @var Process $process */
    $process = $method->invoke($driver, $run);

    return $process;
}

function fakePgBackRestScript(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'pgbackrest-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate a temporary pgBackRest test script.');
    }

    file_put_contents($path, $contents."\n");
    chmod($path, 0755);

    return $path;
}
