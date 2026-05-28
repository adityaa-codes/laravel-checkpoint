<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresLogicalRestoreHandler implements PostgresOperationHandler
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'logical_restore_file' || $operation === 'logical_restore_latest';
    }

    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(
            $this->command($run, $plannedMetadata),
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
            ...$this->targetResolver->resolvedRestoreTargetMetadata($run, $this->config->format),
            'metadata' => [
                'driver' => 'postgres',
                'format' => $this->config->format->value,
                'jobs' => $this->config->jobs,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function command(CommandRun $run, array $plannedMetadata): array
    {
        $format = $this->config->format;
        $target = $this->resolveRestoreTarget($run, $plannedMetadata, $format);
        $command = [
            $this->config->restoreBinary,
            '--dbname='.$this->config->databaseName,
            '--format='.$format->value,
        ];

        if ($format === PostgresFormat::Directory) {
            $command[] = '--jobs='.$this->config->jobs;
        }

        if ($this->config->clean) {
            $command[] = '--clean';
        }

        if ($this->config->create) {
            $command[] = '--create';
        }

        $connectionArgs = $this->config->connectionArgs();

        if ($connectionArgs !== []) {
            $command = [...$command, ...$connectionArgs];
        }

        return [
            ...$command,
            ...$this->config->extraArgs('restore'),
            $target,
        ];
    }

    private function resolveRestoreTarget(CommandRun $run, array $plannedMetadata, PostgresFormat $format): string
    {
        if (isset($plannedMetadata['restore_target']) && $plannedMetadata['restore_target'] !== '') {
            $snapshot = $plannedMetadata['restore_target_snapshot'] ?? null;

            return $this->targetResolver->validatedRestoreTarget($plannedMetadata['restore_target'], $format, $snapshot);
        }

        return $this->targetResolver->resolveForRestoreWithFormat($run, $format);
    }
}
