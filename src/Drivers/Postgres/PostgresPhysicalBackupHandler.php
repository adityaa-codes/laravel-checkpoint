<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresPhysicalBackupHandler implements PostgresOperationHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'physical_backup';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        $this->config->ensurePhysicalOutputDirExists();
        $timestamp = now()->format('Ymd_His');

        return new Process(
            $this->command($timestamp),
            timeout: $this->config->commandTimeoutSeconds,
        );
    }

    public function execute(CommandRun $run, array $plannedMetadata): ?array
    {
        return null;
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        return $this->buildProcess($run, $plannedMetadata)->getCommandLine();
    }

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'backup_type' => 'physical_basebackup',
            'metadata' => [
                'driver' => 'postgres',
                'binary' => $this->config->physicalBinary,
                'output_dir' => $this->config->physicalOutputDir,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function command(string $timestamp): array
    {
        return [
            $this->config->physicalBinary,
            '-D', $this->config->physicalOutputDir.'/backup_'.$timestamp,
            '-Ft',
            '-z',
            '-X', 'stream',
            '-P',
        ];
    }
}
