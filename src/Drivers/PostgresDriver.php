<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresDriverConfig;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresOperationHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSelfExecutingHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSnapshotService;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/** @internal */
final class PostgresDriver implements BackupDriver
{
    /**
     * @param  array<PostgresOperationHandler>  $handlers
     */
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresSnapshotService $snapshot,
        private readonly array $handlers,
        private readonly RestoreSafetyGuard $restoreSafetyGuard,
        private readonly PostRestoreVerificationBuilder $postRestoreVerificationBuilder,
        private readonly CommandOutputCapture $outputCapture,
        private readonly CommandOutputStore $outputStore,
        private readonly CommandLineRedactor $commandLineRedactor,
        private readonly BackupArtifactUploader $artifactUploader,
    ) {}

    public function execute(CommandRun $run): void
    {
        $outputSession = null;

        try {
            if (! $run->claimPendingExecution()) {
                return;
            }

            $run = $run->fresh() ?? $run;
            $handler = $this->resolveHandler($run->operation);
            $plannedMetadata = $this->redactedReplicationMetadata($handler->plannedMetadata($run));

            $restoreAudit = $this->restoreSafetyGuard->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->commandLineRedactor->redact($handler->displayCommandLine($run, $plannedMetadata));

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata(Arr::except($plannedMetadata, ['restore_target_snapshot']));

            event(new BackupStarted($run));

            $this->logger()->info('Starting Postgres operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            if ($handler instanceof PostgresSelfExecutingHandler) {
                $result = $handler->execute($run, $plannedMetadata);

                $storedOutput = $this->outputStore->persist($run, $result['output']);
                $output = $storedOutput['command_output'] ?? '';
                $exitCode = (int) $result['exit_code'];
                $completedMetadata = $this->completedMetadata(
                    $run,
                    $plannedMetadata,
                    $exitCode,
                    [
                        ...$result['metadata'],
                        ...$storedOutput['metadata'],
                    ],
                );
            } else {
                $process = $handler->buildProcess($run, $plannedMetadata);
                $outputSession = $this->outputStore->startCapture($run);
                $capturedOutput = $this->outputCapture->captureProcess(
                    $process,
                    function (string $chunk, string $type) use ($run, &$outputSession): void {
                        $this->outputStore->appendCaptureChunk($outputSession, $chunk);
                        $run->recordHeartbeatIfDue();
                    },
                );
                $storedOutput = $this->outputStore->finishCapture($run, $capturedOutput['output'], $outputSession);
                $outputSession = null;
                $output = $storedOutput['command_output'];
                $exitCode = $process->getExitCode() ?? -1;
                $completedMetadata = $this->completedMetadata(
                    $run,
                    $plannedMetadata,
                    $exitCode,
                    [
                        ...$capturedOutput['metadata'],
                        ...$storedOutput['metadata'],
                    ],
                );
            }

            $run->forceFill([
                'command_output' => $output,
                'exit_code' => $exitCode,
            ])->save();
            $run->recordMetadata($completedMetadata);

            if ($exitCode === 0) {
                $run->markAsSucceeded($exitCode, $output);

                $this->uploadArtifact($run);

                event(new BackupCompleted($run, $exitCode, $output));

                $this->logger()->info('Completed Postgres operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $output);
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('Postgres operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            $this->outputStore->discardCaptureSession($outputSession);

            $run->markAsFailed(output: $exception->getMessage());
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('Postgres operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    private function resolveHandler(string $operation): PostgresOperationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($operation)) {
                return $handler;
            }
        }

        throw new ConfigurationException(
            sprintf('Unsupported Postgres operation [%s].', $operation),
        );
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $captureMetadata
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = is_array($plannedMetadata['metadata'] ?? null) ? $plannedMetadata['metadata'] : [];

        $completed = [
            'metadata' => [
                ...$metadata,
                ...$captureMetadata,
            ],
        ];

        $completed = $this->enrichLogicalBackupMetadata($completed, $run, $plannedMetadata, $exitCode, $metadata);
        $completed = $this->enrichOperationMetadata($completed, $run);
        $completed = $this->attachPostRestoreVerification($completed, $run, $exitCode, $plannedMetadata);

        return $completed;
    }

    /**
     * @param  array<string, mixed>  $completed
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function enrichLogicalBackupMetadata(array $completed, CommandRun $run, array $plannedMetadata, int $exitCode, array $metadata): array
    {
        if ($run->operation !== 'logical_backup') {
            return $completed;
        }

        $artifactPath = $plannedMetadata['artifact_path'] ?? null;

        if (is_string($artifactPath)) {
            $completed['backup_size_bytes'] = $this->snapshot->pathSize($artifactPath);
        }

        if ($exitCode === 0 && is_string($artifactPath)) {
            $completed['metadata']['artifact_snapshot'] = $this->snapshot->safeSnapshot(
                $artifactPath,
                (string) ($metadata['format'] ?? $this->config->format),
            );
            $completed['last_known_good_at'] = now();
        }

        return $completed;
    }

    /**
     * @param  array<string, mixed>  $completed
     * @return array<string, mixed>
     */
    private function enrichOperationMetadata(array $completed, CommandRun $run): array
    {
        if (in_array($run->operation, ['logical_restore_latest', 'logical_restore_file', 'physical_restore'], true)) {
            $completed['metadata']['restored_via'] = $run->operation === 'physical_restore' ? 'pg_basebackup' : 'pg_restore';
        }

        if ($run->operation === 'replication_sync') {
            $completed['metadata']['replicated_via'] = 'postgres';
        }

        return $completed;
    }

    /**
     * @param  array<string, mixed>  $completed
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function attachPostRestoreVerification(array $completed, CommandRun $run, int $exitCode, array $plannedMetadata): array
    {
        $postRestoreVerification = $this->postRestoreVerificationBuilder->build(
            run: $run,
            exitCode: $exitCode,
            metadata: $completed['metadata'],
            restoreTarget: is_string($plannedMetadata['restore_target'] ?? null) ? $plannedMetadata['restore_target'] : null,
        );

        if (! is_array($postRestoreVerification)) {
            return $completed;
        }

        $restoreAudit = is_array($completed['metadata']['restore_audit'] ?? null)
            ? $completed['metadata']['restore_audit']
            : [];
        $restoreAudit['post_restore_verification'] = $postRestoreVerification;
        $completed['metadata']['restore_audit'] = $restoreAudit;

        return $completed;
    }

    private function uploadArtifact(CommandRun $run): void
    {
        if ($run->operation === 'physical_backup') {
            $backupDirs = File::glob($this->config->physicalOutputDir.'/backup_*', GLOB_ONLYDIR);

            if ($backupDirs === []) {
                return;
            }

            $artifactPath = collect($backupDirs)->last();
        } else {
            $artifactPath = $this->config->format === 'directory'
                ? $this->config->outputDir.'/'.$this->config->outputPrefix.'-'.$run->getKey()
                : $this->config->outputDir.'/'.$this->config->outputPrefix.'-'.$run->getKey().'.'.$this->config->fileExtension;
        }

        $results = $this->artifactUploader->upload($artifactPath);

        if ($results !== []) {
            $run->recordMetadata([
                'metadata' => [
                    'uploads' => $results,
                ],
            ]);
        }
    }

    private function redactedReplicationMetadata(array $plannedMetadata): array
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

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $restoreAudit
     * @return array<string, mixed>
     */
    private function mergeRestoreAuditMetadata(array $plannedMetadata, array $restoreAudit): array
    {
        if ($restoreAudit === []) {
            return $plannedMetadata;
        }

        $metadata = is_array($plannedMetadata['metadata'] ?? null) ? $plannedMetadata['metadata'] : [];

        return [
            ...$plannedMetadata,
            'metadata' => [
                ...$metadata,
                ...$restoreAudit,
            ],
        ];
    }

    private function logger(): LoggerInterface
    {
        return Log::channel($this->config->logChannel);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $extra = []): array
    {
        return collect([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => 'postgres',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            'restore_decision_event_count' => $this->restoreDecisionEventCount($run),
            ...$extra,
        ])->filter(static fn (mixed $value): bool => $value !== null)->all();
    }

    private function restoreDecisionEventCount(CommandRun $run): ?int
    {
        if (! in_array($run->operation, ['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore', 'replication_sync'], true)) {
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
