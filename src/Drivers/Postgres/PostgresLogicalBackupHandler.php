<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresLogicalBackupHandler implements PostgresOperationHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'logical_backup';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(
            $this->command($run),
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
            'backup_type' => 'logical_export',
            'artifact_path' => $this->targetResolver->backupTarget($run),
            'verification_state' => 'not_applicable',
            'metadata' => [
                'driver' => 'postgres',
                'format' => $this->config->format,
                'jobs' => $this->config->jobs,
                'compress_level' => $this->config->compressLevel,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function command(CommandRun $run): array
    {
        $format = $this->config->format;
        $target = $this->targetResolver->backupTarget($run);
        $command = [
            $this->config->dumpBinary,
            '--dbname='.$this->config->databaseName,
            '--format='.$format,
            '--file='.$target,
        ];

        if ($format === 'directory') {
            $command[] = '--jobs='.$this->config->jobs;
        }

        $command[] = '--compress='.$this->config->compressLevel;

        return [
            ...$command,
            ...$this->config->extraArgs('backup'),
        ];
    }
}
