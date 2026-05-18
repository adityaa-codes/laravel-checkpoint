<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresReplicationOrchestrator
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly ReplicationFailureSuggestionMapper $suggestionMapper,
        private readonly PostgresSnapshotService $snapshot,
    ) {}

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $this->assertLocalConfiguredReplicationSemantics($replication);
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('postgres replication requires a writable staging artifact path.');
        }

        $applyRequested = (bool) ($replication['apply_requested'] ?? false);
        $dryRunRequested = (bool) ($replication['dry_run_requested'] ?? true);
        $this->assertReplicationGovernanceAllowsApply($replication, $applyRequested, $dryRunRequested);

        $dryRun = $this->runProcess($this->dryRunCommand($plannedMetadata));

        if ($dryRun['exit_code'] !== 0) {
            $this->cleanupArtifact($artifactPath);
            $analysis = $this->failureAnalysis(
                stage: 'dry_run_export',
                failureOutput: $dryRun['output'],
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output'].$this->renderDebugSuggestions($analysis),
                'exit_code' => $dryRun['exit_code'],
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'dry_run_export',
                            'reason' => 'postgres dry-run export command failed.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        $sourceSnapshot = $this->snapshot->safeSnapshot($artifactPath, 'custom');

        if (! $applyRequested || $dryRunRequested) {
            $this->cleanupArtifact($artifactPath);

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output'],
                'exit_code' => 0,
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'dry_run_only',
                        'sanity' => [
                            'source_snapshot' => $sourceSnapshot,
                            'method' => 'artifact_hash',
                            'destination_check' => 'skipped',
                            'fallback_reason' => 'apply_not_requested_or_dry_run_enforced',
                        ],
                    ],
                ],
            ];
        }

        $overwriteAllowed = (bool) ($replication['overwrite_destination'] ?? false) || (bool) ($replication['force_requested'] ?? false);

        if (! $overwriteAllowed) {
            $this->cleanupArtifact($artifactPath);
            $analysis = $this->failureAnalysis(
                stage: 'apply_gate',
                failureOutput: 'Destination overwrite denied by policy.',
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                    'overwrite_destination' => (bool) ($replication['overwrite_destination'] ?? false),
                    'force_requested' => (bool) ($replication['force_requested'] ?? false),
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                    ."\n[replication_sync:apply_gate]\nDestination overwrite denied by policy."
                    .$this->renderDebugSuggestions($analysis),
                'exit_code' => 2,
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'apply_gate',
                            'reason' => 'Destination overwrite is blocked by default.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        $apply = $this->runProcess($this->applyCommand($artifactPath));
        $this->cleanupArtifact($artifactPath);

        $sanity = [
            'source_snapshot' => $sourceSnapshot,
            'method' => 'artifact_hash',
            'destination_check' => $apply['exit_code'] === 0 ? 'apply_exit_code_zero' : 'apply_failed',
            'fallback_reason' => 'live_destination_checksum_not_available_in_v1',
        ];

        if ($apply['exit_code'] !== 0) {
            $analysis = $this->failureAnalysis(
                stage: 'apply_restore',
                failureOutput: $apply['output'],
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                    'sanity_method' => $sanity['method'],
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                    ."\n[replication_sync:apply]\n".$apply['output']
                    .$this->renderDebugSuggestions($analysis),
                'exit_code' => $apply['exit_code'],
                'metadata' => [
                    ...$dryRun['metadata'],
                    ...$apply['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'sanity' => $sanity,
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'apply_restore',
                            'reason' => 'pg_restore apply phase failed on destination.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                ."\n[replication_sync:apply]\n".$apply['output'],
            'exit_code' => 0,
            'metadata' => [
                ...$dryRun['metadata'],
                ...$apply['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'applied',
                    'sanity' => $sanity,
                ],
            ],
        ];
    }

    public function dryRunCommand(array $plannedMetadata): array
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('postgres replication requires a writable staging artifact path.');
        }

        return [
            $this->config->dumpBinary,
            '--dbname='.$this->config->databaseName,
            '--format=custom',
            '--file='.$artifactPath,
            ...$this->config->extraArgs('backup'),
        ];
    }

    /**
     * @return list<string>
     */
    public function applyCommand(string $artifactPath): array
    {
        return [
            $this->config->restoreBinary,
            '--dbname='.$this->config->databaseName,
            '--format=custom',
            ...$this->config->extraArgs('restore'),
            $artifactPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replicationPlan(CommandRun $run): array
    {
        $payload = $this->replicationPayload($run);
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $replicationMetadata = is_array($metadata['replication'] ?? null) ? $metadata['replication'] : [];
        $engine = Str::lower(Str::trim((string) ($replicationMetadata['engine'] ?? '')));

        if ($engine === '') {
            throw new ConfigurationException('replication_sync requires replication.engine metadata.');
        }

        if ($engine !== 'pgsql') {
            throw new ConfigurationException(sprintf(
                'Unsupported replication engine [%s] for Postgres driver. Postgres driver supports pgsql -> pgsql only.',
                $engine,
            ));
        }

        return [
            'engine' => 'pgsql',
            'source' => is_array($replicationMetadata['source'] ?? null) ? $replicationMetadata['source'] : null,
            'destination' => is_array($replicationMetadata['destination'] ?? null) ? $replicationMetadata['destination'] : null,
            'dry_run_requested' => (bool) ($payload['dry_run'] ?? true),
            'apply_requested' => (bool) ($payload['apply'] ?? false),
            'force_requested' => (bool) ($payload['force'] ?? false),
            'overwrite_destination' => (bool) ($payload['overwrite_destination'] ?? false),
            'governance_preflight' => is_array($payload['governance_preflight'] ?? null)
                ? $payload['governance_preflight']
                : (is_array($replicationMetadata['governance_preflight'] ?? null) ? $replicationMetadata['governance_preflight'] : null),
            'artifact_path' => $this->replicationArtifactPath($run),
        ];
    }

    public function replicationArtifactPath(CommandRun $run): string
    {
        return sprintf(
            '%s/%s-%d.dump',
            Str::finish($this->config->outputDir, '/'),
            'checkpoint-replication',
            (int) $run->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    public function replicationDryRunCommandForDisplay(array $plannedMetadata): array
    {
        return $this->dryRunCommand($plannedMetadata);
    }

    /**
     * @return list<string>
     */
    public function replicationApplyCommandForDisplay(string $artifactPath): array
    {
        return $this->applyCommand($artifactPath);
    }

    /**
     * @param  list<string>  $command
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function runProcess(array $command): array
    {
        $process = new Process(
            $command,
            timeout: $this->config->commandTimeoutSeconds,
        );

        $process->run();

        return [
            'output' => $process->getOutput().$process->getErrorOutput(),
            'exit_code' => $process->getExitCode() ?? -1,
            'metadata' => [],
        ];
    }

    private function cleanupArtifact(string $artifactPath): void
    {
        File::delete($artifactPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function replicationPayload(CommandRun $run): array
    {
        $argument = Str::trim((string) ($run->argument_text ?? ''));

        if ($argument === '') {
            throw new ConfigurationException('replication_sync requires a JSON payload argument.');
        }

        try {
            $payload = json_decode($argument, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('replication_sync argument must be valid JSON.', $exception->getCode(), previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ConfigurationException('replication_sync argument payload must decode to an object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $replication
     */
    private function assertLocalConfiguredReplicationSemantics(array $replication): void
    {
        $source = is_array($replication['source'] ?? null) ? $replication['source'] : [];
        $destination = is_array($replication['destination'] ?? null) ? $replication['destination'] : [];
        $sourceKind = Str::lower(Str::trim((string) ($source['kind'] ?? '')));
        $destinationKind = Str::lower(Str::trim((string) ($destination['kind'] ?? '')));
        $usesHostBoundEndpoint = in_array($sourceKind, ['dsn', 'key_value'], true)
            || in_array($destinationKind, ['dsn', 'key_value'], true);

        if (! $usesHostBoundEndpoint) {
            return;
        }

        throw new ConfigurationException(
            'postgres replication execution currently supports only local/configured endpoint semantics. '
            .'Remote or cross-host source/destination endpoints are not supported. '
            .'Use matching local profile endpoints and run remote replication through external tooling.',
        );
    }

    /**
     * @param  array<string, mixed>  $replication
     */
    private function assertReplicationGovernanceAllowsApply(array $replication, bool $applyRequested, bool $dryRunRequested): void
    {
        if (! $applyRequested || $dryRunRequested) {
            return;
        }

        $preflight = is_array($replication['governance_preflight'] ?? null) ? $replication['governance_preflight'] : null;

        if ($preflight !== null && (bool) ($preflight['allowed'] ?? false)) {
            return;
        }

        $reasons = $preflight['blocked_reasons'] ?? null;
        $reasonText = is_array($reasons) && $reasons !== []
            ? collect($reasons)->map(static fn (mixed $reason): string => (string) $reason)->join(', ')
            : 'missing_governance_preflight_metadata';

        throw new ConfigurationException(sprintf(
            'Replication apply is blocked by governance preflight at execution time: %s. Re-queue with approved destination/change window, or run dry-run mode.',
            $reasonText,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     category:string,
     *     signature:string,
     *     immediate_fix:string,
     *     deeper_diagnostics:list<string>,
     *     diagnostics:array<string, mixed>
     * }
     */
    private function failureAnalysis(string $stage, string $failureOutput, array $context = []): array
    {
        return $this->suggestionMapper->map($stage, $failureOutput, $context);
    }

    /**
     * @param  array{
     *     immediate_fix:string,
     *     deeper_diagnostics:list<string>
     * } $analysis
     * @return list<string>
     */
    private function legacySuggestions(array $analysis): array
    {
        return [
            $analysis['immediate_fix'],
            ...$analysis['deeper_diagnostics'],
        ];
    }

    /**
     * @param  array{
     *     category:string,
     *     immediate_fix:string,
     *     deeper_diagnostics:list<string>
     * } $analysis
     */
    private function renderDebugSuggestions(array $analysis): string
    {
        $lines = [
            '',
            '[replication_sync:debug]',
            sprintf('category: %s', $analysis['category']),
            sprintf('immediate_fix: %s', $analysis['immediate_fix']),
        ];

        foreach ($analysis['deeper_diagnostics'] as $index => $step) {
            $lines[] = sprintf('diagnostic_%d: %s', $index + 1, $step);
        }

        return "\n".collect($lines)->join("\n");
    }
}
