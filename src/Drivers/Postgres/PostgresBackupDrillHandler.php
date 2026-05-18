<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresBackupDrillHandler implements PostgresOperationHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'backup_drill';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        if ($this->config->drillCommand !== '') {
            return new Process(
                $this->customDrillCommand($plannedMetadata),
                timeout: $this->config->commandTimeoutSeconds,
            );
        }

        return new Process(
            $this->defaultDrillCommand($plannedMetadata),
            timeout: $this->config->commandTimeoutSeconds,
        );
    }

    public function execute(CommandRun $run, array $plannedMetadata): ?array
    {
        return null;
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        if ($this->config->drillCommand !== '') {
            return $this->buildProcess($run, $plannedMetadata)->getCommandLine();
        }

        return sprintf('pg_restore -l %s', $plannedMetadata['drill_artifact_path'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'drill_artifact_path' => $this->targetResolver->latestBackupTarget($this->config->format),
            'metadata' => [
                'driver' => 'postgres',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function customDrillCommand(array $plannedMetadata): array
    {
        $argv = preg_split('/\s+/', $this->config->drillCommand);

        if ($argv === false) {
            throw new ConfigurationException('checkpoint.drivers.postgres.drill_command must contain a valid executable token.');
        }

        $replacements = [
            '{db}' => $this->config->databaseName,
            '{target}' => $plannedMetadata['drill_artifact_path'] ?? '',
        ];

        return array_values(collect($argv)->map(
            static fn (string $token): string => Str::replace(
                array_keys($replacements),
                array_values($replacements),
                $token,
            ),
        )->all());
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function defaultDrillCommand(array $plannedMetadata): array
    {
        $target = $plannedMetadata['drill_artifact_path'] ?? $this->targetResolver->latestBackupTarget($this->config->format);

        return [
            $this->config->restoreBinary,
            '--list',
            $target,
        ];
    }
}
