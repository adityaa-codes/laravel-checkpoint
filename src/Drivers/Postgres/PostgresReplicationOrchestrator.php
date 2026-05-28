<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class PostgresReplicationOrchestrator
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresSnapshotService $snapshot,
        private Filesystem $filesystem,
        private PostgresReplicationResultBuilder $resultBuilder,
    ) {}

    /**
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        $replication = $plannedMetadata['metadata']['replication'] ?? [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw ConfigurationException::missingReplicationArtifactPath();
        }

        $this->assertLocalConfiguredReplicationSemantics($replication);

        $applyRequested = (bool) ($replication['apply_requested'] ?? false);
        $dryRunRequested = (bool) ($replication['dry_run_requested'] ?? true);
        $this->assertReplicationGovernanceAllowsApply($replication, $applyRequested, $dryRunRequested);

        $dryRun = $this->runProcess($this->dryRunCommand($plannedMetadata));

        if ($dryRun['exit_code'] !== 0) {
            $this->cleanupArtifact($artifactPath);

            return $this->resultBuilder->dryRunFailure($replication, $dryRun);
        }

        $sourceSnapshot = $this->snapshot->safeSnapshot($artifactPath, PostgresFormat::Custom);

        if (! $applyRequested || $dryRunRequested) {
            $this->cleanupArtifact($artifactPath);

            return $this->resultBuilder->dryRunSuccess($replication, $dryRun, $sourceSnapshot);
        }

        $overwriteAllowed = (bool) ($replication['overwrite_destination'] ?? false)
            || (bool) ($replication['force_requested'] ?? false);

        if (! $overwriteAllowed) {
            $this->cleanupArtifact($artifactPath);

            return $this->resultBuilder->applyGateFailure($replication, $dryRun);
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
            return $this->resultBuilder->applyFailure($replication, $dryRun, $apply, $sanity);
        }

        return $this->resultBuilder->applySuccess($replication, $dryRun, $apply, $sanity);
    }

    /**
     * @return list<string>
     */
    public function dryRunCommand(array $plannedMetadata): array
    {
        $replication = $plannedMetadata['metadata']['replication'] ?? [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw ConfigurationException::missingReplicationArtifactPath();
        }

        $command = [
            $this->config->dumpBinary,
            '--dbname='.$this->config->databaseName,
            '--format=custom',
            '--file='.$artifactPath,
        ];

        $connectionArgs = $this->config->connectionArgs();

        if ($connectionArgs !== []) {
            $command = [...$command, ...$connectionArgs];
        }

        return [
            ...$command,
            ...$this->config->extraArgs('replication'),
        ];
    }

    /**
     * @return list<string>
     */
    public function applyCommand(string $artifactPath): array
    {
        $command = [
            $this->config->restoreBinary,
            '--dbname='.$this->config->databaseName,
            '--format=custom',
        ];

        $connectionArgs = $this->config->connectionArgs();

        if ($connectionArgs !== []) {
            $command = [...$command, ...$connectionArgs];
        }

        return [
            ...$command,
            ...$this->config->extraArgs('replication'),
            $artifactPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function replicationPlan(CommandRun $run): array
    {
        $payload = $this->replicationPayload($run);
        $metadata = $run->metadata ?? [];
        $replicationMetadata = $metadata['replication'] ?? [];
        $engine = Str::lower(Str::trim((string) ($replicationMetadata['engine'] ?? '')));

        if ($engine === '') {
            throw ConfigurationException::missingReplicationMetadata('engine');
        }

        if ($engine !== 'pgsql') {
            throw ConfigurationException::unsupportedReplicationEngine($engine);
        }

        return [
            'engine' => 'pgsql',
            'source' => $replicationMetadata['source'] ?? null,
            'destination' => $replicationMetadata['destination'] ?? null,
            'dry_run_requested' => (bool) ($payload['dry_run'] ?? true),
            'apply_requested' => (bool) ($payload['apply'] ?? false),
            'force_requested' => (bool) ($payload['force'] ?? false),
            'overwrite_destination' => (bool) ($payload['overwrite_destination'] ?? false),
            'governance_preflight' => $payload['governance_preflight'] ?? ($replicationMetadata['governance_preflight'] ?? null),
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
        $this->filesystem->delete($artifactPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function replicationPayload(CommandRun $run): array
    {
        $argument = Str::trim($run->argument_text ?? '');

        if ($argument === '') {
            throw ConfigurationException::missingReplicationMetadata('payload');
        }

        try {
            $payload = json_decode($argument, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ConfigurationException::invalidReplicationJson();
        }

        if (! is_array($payload)) {
            throw ConfigurationException::invalidReplicationJson();
        }

        return $payload;
    }

    private function assertLocalConfiguredReplicationSemantics(array $replication): void
    {
        $source = $replication['source'] ?? [];
        $destination = $replication['destination'] ?? [];
        $sourceKind = Str::lower(Str::trim((string) ($source['kind'] ?? '')));
        $destinationKind = Str::lower(Str::trim((string) ($destination['kind'] ?? '')));
        $usesHostBoundEndpoint = in_array($sourceKind, ['dsn', 'key_value'], true)
            || in_array($destinationKind, ['dsn', 'key_value'], true);

        if (! $usesHostBoundEndpoint) {
            return;
        }

        throw ConfigurationException::replicationLocalOnly();
    }

    private function assertReplicationGovernanceAllowsApply(array $replication, bool $applyRequested, bool $dryRunRequested): void
    {
        if (! $applyRequested || $dryRunRequested) {
            return;
        }

        $preflight = $replication['governance_preflight'] ?? null;

        if (is_array($preflight) && (bool) ($preflight['allowed'] ?? false)) {
            return;
        }

        $reasons = is_array($preflight['blocked_reasons'] ?? null) ? $preflight['blocked_reasons'] : null;
        $reasonText = is_array($reasons) && $reasons !== []
            ? implode(', ', $reasons)
            : 'missing_governance_preflight_metadata';

        throw ConfigurationException::governanceBlocked($reasonText);
    }
}
