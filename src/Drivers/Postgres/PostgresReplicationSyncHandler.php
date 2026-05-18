<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresReplicationSyncHandler implements PostgresSelfExecutingHandler
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresReplicationOrchestrator $orchestrator,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'replication_sync';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(
            $this->orchestrator->dryRunCommand($plannedMetadata),
            timeout: $this->config->commandTimeoutSeconds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'metadata' => [
                'driver' => 'postgres',
                'replication' => $this->orchestrator->replicationPlan($run),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        return $this->orchestrator->execute($run, $plannedMetadata);
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
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
