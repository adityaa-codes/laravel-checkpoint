<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalBackupHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresLogicalRestoreHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresReplicationSyncHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Tests\TestCase;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $parameters
 */
function checkpoint_artisan(string $command, array $parameters = []): PendingCommand
{
    $pendingCommand = test()->artisan($command, $parameters);

    if ($pendingCommand instanceof PendingCommand) {
        return $pendingCommand;
    }

    throw new RuntimeException('artisan() did not return a PendingCommand instance.');
}

function checkpoint_fixture_path(string $fixture): string
{
    return __DIR__.'/Fixtures/'.$fixture;
}

function checkpoint_normalize_fixture_value(mixed $value): mixed
{
    if (is_string($value)) {
        return Str::replaceMatches('/(checkpoint-pitr-fixture)-\d+(-)/', '$1$2', $value);
    }

    if (! is_array($value)) {
        return $value;
    }

    if (Arr::isList($value)) {
        return collect($value)->map(checkpoint_normalize_fixture_value(...))->all();
    }

    ksort($value);

    foreach ($value as $key => $item) {
        $value[$key] = checkpoint_normalize_fixture_value($item);
    }

    return $value;
}

/**
 * @param  array<string, mixed>  $payload
 */
function checkpoint_assert_matches_fixture(array $payload, string $fixture): void
{
    $expected = json_decode(File::get(checkpoint_fixture_path($fixture)), true, 512, JSON_THROW_ON_ERROR);

    expect(checkpoint_normalize_fixture_value($payload))
        ->toBe(checkpoint_normalize_fixture_value($expected));
}

function postgresContext(CommandRun $run): DriverContext
{
    return new DriverContext(
        operation: $run->operation,
        argument: $run->argument_text,
        driverName: 'postgres',
        metadata: is_array($run->metadata) ? $run->metadata : [],
        runUuid: (string) $run->getKey(),
    );
}

function resolvePostgresDriver(): PostgresDriver
{
    return app(PostgresDriver::class);
}

function resolvePostgresLogicalBackupHandler(): PostgresLogicalBackupHandler
{
    return app(PostgresLogicalBackupHandler::class);
}

function resolvePostgresLogicalRestoreHandler(): PostgresLogicalRestoreHandler
{
    return app(PostgresLogicalRestoreHandler::class);
}

function resolvePostgresReplicationSyncHandler(): PostgresReplicationSyncHandler
{
    return app(PostgresReplicationSyncHandler::class);
}

function fakePostgresScript(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'postgres-test-');

    if ($path === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump test script.');
    }

    File::put($path, $contents."\n");
    File::chmod($path, 0755);

    return $path;
}
