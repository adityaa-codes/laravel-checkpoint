<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresLogicalRestoreHandler implements PostgresOperationHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return in_array($operation, ['logical_restore_file', 'logical_restore_latest'], true);
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
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

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array
    {
        return [
            ...$this->targetResolver->resolvedRestoreTargetMetadata($run, $this->config->format),
            'metadata' => [
                'driver' => 'postgres',
                'format' => $this->config->format,
                'jobs' => $this->config->jobs,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function command(CommandRun $run, array $plannedMetadata): array
    {
        $format = $this->config->format;
        $target = $this->resolveRestoreTarget($run, $plannedMetadata, $format);
        $command = [
            $this->config->restoreBinary,
            '--dbname='.$this->config->databaseName,
            '--format='.$format,
        ];

        if ($format === 'directory') {
            $command[] = '--jobs='.$this->config->jobs;
        }

        if ($this->config->clean) {
            $command[] = '--clean';
        }

        if ($this->config->create) {
            $command[] = '--create';
        }

        return [
            ...$command,
            ...$this->config->extraArgs('restore'),
            $target,
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function resolveRestoreTarget(CommandRun $run, array $plannedMetadata, string $format): string
    {
        if (is_string($plannedMetadata['restore_target'] ?? null) && $plannedMetadata['restore_target'] !== '') {
            $snapshot = is_array($plannedMetadata['restore_target_snapshot'] ?? null)
                ? $plannedMetadata['restore_target_snapshot']
                : null;

            return $this->targetResolver->validatedRestoreTarget($plannedMetadata['restore_target'], $format, $snapshot);
        }

        return $this->targetResolver->resolveForRestoreWithFormat($run, $format);
    }
}
