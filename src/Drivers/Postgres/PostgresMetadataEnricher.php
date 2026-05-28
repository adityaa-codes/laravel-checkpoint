<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;

/** @internal */
final readonly class PostgresMetadataEnricher
{
    public function __construct(
        private PostgresSnapshotService $snapshot,
        private PostgresDriverConfig $config,
        private PostRestoreVerificationBuilder $postRestoreVerificationBuilder,
    ) {}

    public function enrich(DriverContext $context, CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = $plannedMetadata['metadata'] ?? [];

        $completed = [
            'metadata' => [
                ...$metadata,
                ...$captureMetadata,
            ],
        ];

        $completed = $this->enrichLogicalBackup($completed, $context, $plannedMetadata, $exitCode, $metadata);
        $completed = $this->enrichOperation($completed, $context);

        return $this->attachPostRestoreVerification($completed, $run, $exitCode, $plannedMetadata);
    }

    private function enrichLogicalBackup(array $completed, DriverContext $context, array $plannedMetadata, int $exitCode, array $metadata): array
    {
        if ($context->operation !== 'logical_backup') {
            return $completed;
        }

        $artifactPath = $plannedMetadata['artifact_path'] ?? null;

        if ($artifactPath !== null) {
            $completed['backup_size_bytes'] = $this->snapshot->pathSize($artifactPath);
        }

        if ($exitCode === 0 && $artifactPath !== null) {
            $completed['metadata']['artifact_snapshot'] = $this->snapshot->safeSnapshot(
                $artifactPath,
                PostgresFormat::from($metadata['format'] ?? $this->config->format->value),
            );
            $completed['last_known_good_at'] = now();
        }

        return $completed;
    }

    private function enrichOperation(array $completed, DriverContext $context): array
    {
        if (in_array($context->operation, ['logical_restore_latest', 'logical_restore_file', 'physical_restore'], true)) {
            $completed['metadata']['restored_via'] = $context->operation === 'physical_restore' ? 'pg_basebackup' : 'pg_restore';
        }

        if ($context->operation === 'replication_sync') {
            $completed['metadata']['replicated_via'] = 'postgres';
        }

        return $completed;
    }

    private function attachPostRestoreVerification(array $completed, CommandRun $run, int $exitCode, array $plannedMetadata): array
    {
        $postRestoreVerification = $this->postRestoreVerificationBuilder->build(
            run: $run,
            exitCode: $exitCode,
            metadata: $completed['metadata'],
            restoreTarget: $plannedMetadata['restore_target'] ?? null,
        );

        if (! is_array($postRestoreVerification)) {
            return $completed;
        }

        $restoreAudit = $completed['metadata']['restore_audit'] ?? [];
        $restoreAudit['post_restore_verification'] = $postRestoreVerification;
        $completed['metadata']['restore_audit'] = $restoreAudit;

        return $completed;
    }
}
