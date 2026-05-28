<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresBackupDrillHandler implements PostgresOperationHandler
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresRestoreTargetResolver $targetResolver,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'backup_drill';
    }

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
     * @return list<string>
     */
    private function customDrillCommand(array $plannedMetadata): array
    {
        $tokens = preg_split('/\s+/', trim($this->config->drillCommand)) ?: [];

        if ($tokens === [] || ! isset($tokens[0])) {
            throw ConfigurationException::missingDrillExecutable();
        }

        $replacements = [
            '{db}' => $this->config->databaseName,
            '{target}' => $plannedMetadata['drill_artifact_path'] ?? '',
        ];

        return collect($tokens)->map(
            fn (string $token): string => str_replace(
                array_keys($replacements),
                array_values($replacements),
                $token,
            ),
        )->values()->all();
    }

    /**
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
