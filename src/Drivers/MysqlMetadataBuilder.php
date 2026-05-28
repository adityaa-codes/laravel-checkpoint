<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;

final class MysqlMetadataBuilder
{
    public function __construct(
        private readonly MysqlConfiguration $config,
        private readonly MysqlRestoreTargetValidator $validator,
        private readonly PostRestoreVerificationBuilder $postRestoreVerificationBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(DriverContext $context, CommandRun $run): array
    {
        return match ($context->operation) {
            'logical_backup' => [
                'backup_type' => 'logical_export',
                'artifact_path' => $this->config->backupTarget($run),
                'verification_state' => 'not_applicable',
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->config->databaseName(),
                ],
            ],
            'logical_restore_latest', 'logical_restore_file' => [
                ...$this->resolvedRestoreTargetMetadata($context, $run),
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->config->databaseName(),
                    'restore_mode' => 'logical',
                ],
            ],
            'pitr_restore' => [
                'restore_target' => trim((string) ($context->argument ?? '')),
                'pitr_base_target' => $this->validator->latestBackupTarget(),
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->config->databaseName(),
                    'restore_mode' => 'pitr',
                    'binlog_files' => $this->config->pitrBinlogFiles(),
                ],
            ],
            'replication_sync' => [
                'metadata' => [
                    'driver' => 'mysql',
                ],
            ],
            'backup_drill' => [
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->config->databaseName(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array{restore_target:string,restore_target_snapshot:array<string,mixed>}
     */
    public function resolvedRestoreTargetMetadata(DriverContext $context, CommandRun $run): array
    {
        $target = match ($context->operation) {
            'logical_restore_latest' => $this->validator->latestBackupTarget(),
            'logical_restore_file' => $this->validator->restorePathFromArgument($context, $run),
            default => throw ConfigurationException::unsupportedOperation($context->operation, 'mysql restore'),
        };

        return [
            'restore_target' => $target,
            'restore_target_snapshot' => $this->validator->restoreTargetSnapshot($target),
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    public function persistedPlannedMetadata(array $plannedMetadata): array
    {
        unset($plannedMetadata['restore_target_snapshot']);
        unset($plannedMetadata['pitr_base_target']);

        return $plannedMetadata;
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $captureMetadata
     * @return array<string, mixed>
     */
    public function completedMetadata(DriverContext $context, CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = $plannedMetadata['metadata'] ?? ['driver' => 'mysql'];

        if (! is_array($metadata)) {
            $metadata = ['driver' => 'mysql'];
        }

        $completed = [
            'metadata' => [
                ...$metadata,
                ...$captureMetadata,
            ],
        ];

        if ($context->operation === 'logical_backup') {
            $artifactPath = $plannedMetadata['artifact_path'] ?? null;
            $backupSize = is_string($artifactPath) ? $this->validator->pathSize($artifactPath) : null;

            $completed['backup_size_bytes'] = $backupSize;

            if ($exitCode === 0) {
                if (is_string($artifactPath)) {
                    $completed['metadata']['artifact_snapshot'] = $this->validator->artifactSnapshot($artifactPath);
                }

                $completed['last_known_good_at'] = now();
            }
        }

        if (collect(['logical_restore_latest', 'logical_restore_file'])->containsStrict($context->operation)) {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'restored_via' => 'mysql',
            ];
        }

        if ($context->operation === 'pitr_restore') {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'restored_via' => 'mysqlbinlog',
            ];
        }

        if ($context->operation === 'replication_sync') {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'replicated_via' => 'mysqldump+mysql',
            ];
        }

        $postRestoreVerification = $this->postRestoreVerificationBuilder->build(
            run: $run,
            exitCode: $exitCode,
            metadata: $completed['metadata'],
            restoreTarget: is_string($plannedMetadata['restore_target'] ?? null) ? $plannedMetadata['restore_target'] : null,
        );

        if (is_array($postRestoreVerification)) {
            $restoreAudit = is_array($completed['metadata']['restore_audit'] ?? null)
                ? $completed['metadata']['restore_audit']
                : [];
            $restoreAudit['post_restore_verification'] = $postRestoreVerification;
            $completed['metadata']['restore_audit'] = $restoreAudit;
        }

        return $completed;
    }
}
