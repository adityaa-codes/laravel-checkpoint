<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;

/** @internal */
final class MysqlReplicationMetadataBuilder
{
    public function __construct(
        private readonly MysqlConfiguration $config,
        private readonly CommandLineRedactor $commandLineRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function buildPlan(array $payload, CommandRun $run): array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $replicationMetadata = is_array($metadata['replication'] ?? null) ? $metadata['replication'] : [];
        $engine = strtolower(trim((string) ($replicationMetadata['engine'] ?? '')));

        if ($engine === '') {
            throw ConfigurationException::missingReplicationMetadata('engine');
        }

        if ($engine !== 'mysql') {
            throw new ConfigurationException(sprintf(
                'Unsupported replication engine [%s] for mysql driver. mysql driver supports mysql -> mysql only.',
                $engine,
            ));
        }

        return [
            'engine' => 'mysql',
            'source' => is_array($replicationMetadata['source'] ?? null) ? $replicationMetadata['source'] : null,
            'destination' => is_array($replicationMetadata['destination'] ?? null) ? $replicationMetadata['destination'] : null,
            'dry_run_requested' => (bool) ($payload['dry_run'] ?? true),
            'apply_requested' => (bool) ($payload['apply'] ?? false),
            'force_requested' => (bool) ($payload['force'] ?? false),
            'overwrite_destination' => (bool) ($payload['overwrite_destination'] ?? false),
            'governance_preflight' => is_array($payload['governance_preflight'] ?? null)
                ? $payload['governance_preflight']
                : (is_array($replicationMetadata['governance_preflight'] ?? null) ? $replicationMetadata['governance_preflight'] : null),
            'artifact_path' => $this->config->replicationArtifactPath($run),
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    public function redactReplicationMetadata(array $plannedMetadata): array
    {
        if (! is_array($plannedMetadata['metadata']['replication'] ?? null)) {
            return $plannedMetadata;
        }

        $replication = $plannedMetadata['metadata']['replication'];

        foreach (['source', 'destination'] as $role) {
            if (is_array($replication[$role] ?? null) && is_string($replication[$role]['redacted'] ?? null)) {
                $replication[$role]['redacted'] = $this->commandLineRedactor->redact($replication[$role]['redacted']);
            }
        }

        $plannedMetadata['metadata']['replication'] = $replication;

        return $plannedMetadata;
    }

    public function countRestoreDecisionEvents(CommandRun $run): ?int
    {
        if (! collect(['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore', 'replication_sync'])->containsStrict($run->operation)) {
            return null;
        }

        if (! $run->exists) {
            return null;
        }

        return RestoreDecisionEvent::query()
            ->where('command_run_id', (int) $run->getKey())
            ->count();
    }
}
