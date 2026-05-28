<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresReplicationSyncHandler implements PostgresSelfExecutingHandler
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresReplicationOrchestrator $orchestrator,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'replication_sync';
    }

    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(
            $this->orchestrator->dryRunCommand($plannedMetadata),
            timeout: $this->config->commandTimeoutSeconds,
        );
    }

    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'metadata' => [
                'driver' => 'postgres',
                'replication' => $this->orchestrator->replicationPlan($run),
            ],
        ];
    }

    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        return $this->orchestrator->execute($run, $plannedMetadata);
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        $replication = $plannedMetadata['metadata']['replication'] ?? [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');
        $applyRequested = (bool) ($replication['apply_requested'] ?? false);

        $parts = [
            $this->orchestrator->replicationDryRunCommandForDisplay($plannedMetadata),
        ];

        if ($applyRequested && $artifactPath !== '') {
            $parts[] = $this->orchestrator->replicationApplyCommandForDisplay($artifactPath);
        }

        return collect($parts)->values()->map(
            static fn (array $cmd): string => (new Process($cmd))->getCommandLine(),
        )->join(' ; ');
    }
}
