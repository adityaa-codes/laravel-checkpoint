<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBackRestDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/** @internal */
final class PostgresDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        $this->delegateForOperation($run->operation)->execute($run);
    }

    private function delegateForOperation(string $operation): BackupDriver
    {
        if (in_array($operation, ['logical_backup', 'logical_restore_latest', 'logical_restore_file', 'replication_sync'], true)) {
            return $this->resolveNamedDriver('pgdump');
        }

        if (str_starts_with($operation, 'pgbackrest_')) {
            return $this->resolveNamedDriver('pgbackrest');
        }

        throw new ConfigurationException(
            sprintf('Unsupported postgres facade operation [%s].', $operation),
        );
    }

    private function resolveNamedDriver(string $driver): BackupDriver
    {
        $class = config("checkpoint.drivers.{$driver}.class") ?? $this->defaultNamedDriverClass($driver);

        if (! is_string($class) || $class === '') {
            throw new ConfigurationException(
                sprintf('Driver [%s] is not configured for postgres facade routing.', $driver),
            );
        }

        if ($class === self::class) {
            throw new ConfigurationException(
                sprintf('Driver [%s] cannot route to itself.', $driver),
            );
        }

        $resolved = resolve($class);

        if (! $resolved instanceof BackupDriver) {
            throw new ConfigurationException(
                sprintf('Configured driver [%s] must implement [%s].', $class, BackupDriver::class),
            );
        }

        return $resolved;
    }

    private function defaultNamedDriverClass(string $driver): ?string
    {
        return match ($driver) {
            'pgbackrest' => PgBackRestDriver::class,
            'pgdump' => PgDumpDriver::class,
            default => null,
        };
    }
}
