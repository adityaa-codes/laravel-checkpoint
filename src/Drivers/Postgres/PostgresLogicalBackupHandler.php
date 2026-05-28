<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresLogicalBackupHandler implements PostgresOperationHandler
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'logical_backup';
    }

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

    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'backup_type' => 'logical_export',
            'artifact_path' => $this->targetResolver->backupTarget($run),
            'verification_state' => 'not_applicable',
            'metadata' => [
                'driver' => 'postgres',
                'format' => $this->config->format->value,
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
            '--format='.$format->value,
            '--file='.$target,
        ];

        if ($format === PostgresFormat::Directory) {
            $command[] = '--jobs='.$this->config->jobs;
        }

        $command[] = '--compress='.$this->config->compressLevel;

        $connectionArgs = $this->config->connectionArgs();

        if ($connectionArgs !== []) {
            $command = [...$command, ...$connectionArgs];
        }

        return [
            ...$command,
            ...$this->config->extraArgs('backup'),
        ];
    }
}
