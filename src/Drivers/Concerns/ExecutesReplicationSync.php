<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Concerns;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;

/** @internal */
trait ExecutesReplicationSync
{
    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeReplicationSync(CommandRun $run, array $plannedMetadata): array
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $this->assertLocalConfiguredReplicationSemantics($replication);
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException(sprintf(
                '%s replication requires a writable staging artifact path.',
                $this->replicationDriverLabel(),
            ));
        }

        $applyRequested = (bool) ($replication['apply_requested'] ?? false);
        $dryRunRequested = (bool) ($replication['dry_run_requested'] ?? true);
        $this->assertReplicationGovernanceAllowsApply($replication, $applyRequested, $dryRunRequested);

        $dryRun = $this->executeDryRunReplication($plannedMetadata, $run);

        if ($dryRun['exit_code'] !== 0) {
            $this->cleanupReplicationArtifact($artifactPath);
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
                            'reason' => sprintf('%s dry-run export command failed.', $this->replicationDriverLabel()),
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        $sourceSnapshot = $this->replicationArtifactSnapshot($artifactPath);
        $overwriteAllowed = (bool) ($replication['overwrite_destination'] ?? false) || (bool) ($replication['force_requested'] ?? false);

        if (! $applyRequested || $dryRunRequested) {
            $this->cleanupReplicationArtifact($artifactPath);

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

        if (! $overwriteAllowed) {
            $this->cleanupReplicationArtifact($artifactPath);
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

        $apply = $this->executeApplyReplication($artifactPath, $run);
        $this->cleanupReplicationArtifact($artifactPath);

        $sanity = [
            'source_snapshot' => $sourceSnapshot,
            'method' => 'artifact_hash',
            'destination_check' => $apply['exit_code'] === 0 ? 'apply_exit_code_zero' : 'apply_failed',
            'fallback_reason' => 'live_destination_checksum_not_available_in_v1',
        ];

        if ($apply['exit_code'] !== 0) {
            $analysis = $this->failureAnalysis(
                stage: 'apply_import',
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
                            'stage' => 'apply_import',
                            'reason' => sprintf('%s apply phase failed while importing staged artifact.', $this->replicationDriverLabel()),
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

    /**
     * @param  array<string, mixed>  $replication
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    abstract private function executeDryRunReplication(array $plannedMetadata, CommandRun $run): array;

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    abstract private function executeApplyReplication(string $artifactPath, CommandRun $run): array;

    /**
     * @return array<string, mixed>|null
     */
    abstract private function replicationArtifactSnapshot(string $path): ?array;

    abstract private function replicationDriverLabel(): string;

    protected function cleanupReplicationArtifact(string $artifactPath): void
    {
        @unlink($artifactPath);
    }

    /**
     * @return array<string, mixed>
     */
    protected function replicationPayload(CommandRun $run): array
    {
        $argument = trim((string) ($run->argument_text ?? ''));

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
    protected function assertLocalConfiguredReplicationSemantics(array $replication): void
    {
        $source = is_array($replication['source'] ?? null) ? $replication['source'] : [];
        $destination = is_array($replication['destination'] ?? null) ? $replication['destination'] : [];
        $sourceKind = strtolower(trim((string) ($source['kind'] ?? '')));
        $destinationKind = strtolower(trim((string) ($destination['kind'] ?? '')));
        $usesHostBoundEndpoint = in_array($sourceKind, ['dsn', 'key_value'], true)
            || in_array($destinationKind, ['dsn', 'key_value'], true);

        if (! $usesHostBoundEndpoint) {
            return;
        }

        throw new ConfigurationException(
            sprintf(
                '%s replication execution currently supports only local/configured endpoint semantics. '
                .'Remote or cross-host source/destination endpoints are not supported. '
                .'Use matching local profile endpoints and run remote replication through external tooling.',
                $this->replicationDriverLabel(),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $replication
     */
    protected function assertReplicationGovernanceAllowsApply(array $replication, bool $applyRequested, bool $dryRunRequested): void
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
            ? implode(', ', array_map(static fn (mixed $reason): string => (string) $reason, $reasons))
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
    protected function failureAnalysis(string $stage, string $failureOutput, array $context = []): array
    {
        return $this->suggestionMapper()->map($stage, $failureOutput, $context);
    }

    /**
     * @param  array{
     *     immediate_fix:string,
     *     deeper_diagnostics:list<string>
     * } $analysis
     * @return list<string>
     */
    protected function legacySuggestions(array $analysis): array
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
    protected function renderDebugSuggestions(array $analysis): string
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

        return "\n".implode("\n", $lines);
    }

    protected function suggestionMapper(): ReplicationFailureSuggestionMapper
    {
        return resolve(ReplicationFailureSuggestionMapper::class);
    }
}
