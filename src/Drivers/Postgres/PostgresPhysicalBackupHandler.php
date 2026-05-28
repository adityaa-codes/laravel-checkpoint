<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresPhysicalCompression;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresPhysicalBackupHandler implements PostgresOperationHandler
{
    public function __construct(
        private PostgresDriverConfig $config,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'physical_backup';
    }

    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        $this->config->ensurePhysicalOutputDirExists();
        $timestamp = $plannedMetadata['timestamp'] ?? now()->format('Ymd_His');

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

    public function plannedMetadata(CommandRun $run): array
    {
        $timestamp = now()->format('Ymd_His');
        $artifactPath = $this->config->physicalOutputDir.'/backup_'.$timestamp;

        return [
            'timestamp' => $timestamp,
            'backup_type' => 'physical_basebackup',
            'artifact_path' => $artifactPath,
            'metadata' => [
                'driver' => 'postgres',
                'binary' => $this->config->physicalBinary,
                'output_dir' => $this->config->physicalOutputDir,
                'wal_method' => $this->config->physicalWalMethod->value,
                'compression' => $this->config->physicalCompression->value,
                'checkpoint' => $this->config->physicalCheckpoint->value,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function command(string $timestamp): array
    {
        $outputPath = $this->config->physicalOutputDir.'/backup_'.$timestamp;

        $command = [
            $this->config->physicalBinary,
            '-D', $outputPath,
        ];

        $command = $this->appendFormatAndCompression($command);

        $command[] = '-X';
        $command[] = $this->config->physicalWalMethod->value;

        $command[] = '-P';

        $command[] = '--checkpoint='.$this->config->physicalCheckpoint->value;

        $command = $this->appendConnectionArgs($command);

        return [
            ...$command,
            ...$this->config->physicalExtraArgs,
        ];
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function appendFormatAndCompression(array $command): array
    {
        if ($this->config->physicalCompression === PostgresPhysicalCompression::None) {
            $command[] = '-Fp';

            return $command;
        }

        $command[] = '-Ft';

        $compressionMap = [
            PostgresPhysicalCompression::Gzip->value => '-z',
            PostgresPhysicalCompression::Lz4->value => '--compress-lz4',
            PostgresPhysicalCompression::Zstd->value => '--compress-zstd',
        ];

        $compressionFlag = $compressionMap[$this->config->physicalCompression->value] ?? null;

        if ($compressionFlag !== null) {
            $command[] = $compressionFlag;
        }

        return $command;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function appendConnectionArgs(array $command): array
    {
        $connectionArgs = $this->config->connectionArgs();

        if ($connectionArgs !== []) {
            return [...$command, ...$connectionArgs];
        }

        return $command;
    }
}
