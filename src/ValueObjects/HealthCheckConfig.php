<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

final readonly class HealthCheckConfig
{
    /**
     * @param  array<string, string>  $bin
     * @param  array<string, int|float>  $obs
     * @param  array{allowedEnvironments:list<string>,allowedDatabases:list<string>,allowInCi:bool,requireVerifiedBackup:bool}  $restore
     * @param  list<array<string, mixed>>  $driverBinaries
     */
    public function __construct(
        public string $driver,
        public string $queueName,
        public string $logChannel,
        public string $environment,
        public string $currentDatabaseName,
        public ?string $lockStore,
        public array $bin,
        public array $obs,
        public array $restore,
        public string $commandRunsTable,
        public string $backupDrillRunsTable,
        public string $verificationRunsTable,
        public array $driverBinaries = [],
    ) {}
}
