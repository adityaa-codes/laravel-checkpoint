<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresDriverConfig;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresMetadataEnricher;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresOperationHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSelfExecutingHandler;
use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresSnapshotService;
use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Events\PostgresLogicalBackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\PostgresLogicalRestoreCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\PostgresPhysicalBackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\PostgresReplicationCompleted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverResult;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
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
        private readonly PostgresMetadataEnricher $metadataEnricher,
        private readonly CommandOutputCapture $outputCapture,
        private readonly CommandOutputStore $outputStore,
        private readonly CommandLineRedactor $commandLineRedactor,
        private readonly BackupArtifactUploader $artifactUploader,
        private readonly Dispatcher $events,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(DriverContext $context, CommandRun $run): DriverResult
    {
        $outputSession = null;

        try {
            $run = $run->fresh() ?? $run;
            $handler = $this->resolveHandler($context->operation);
            $plannedMetadata = $this->redactedReplicationMetadata($handler->plannedMetadata($run));

            $restoreAudit = $this->restoreSafetyGuard->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->commandLineRedactor->redact($handler->displayCommandLine($run, $plannedMetadata));

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata(Arr::except($plannedMetadata, ['restore_target_snapshot']));

            $this->logger->info('Starting Postgres operation', $this->logContext($context, $run, [
                'command_line' => $displayCommandLine,
            ]));

            if ($handler instanceof PostgresSelfExecutingHandler) {
                $result = $handler->execute($run, $plannedMetadata);

                $storedOutput = $this->outputStore->persist($run, $result['output']);
                $output = $storedOutput['command_output'] ?? '';
                $exitCode = (int) $result['exit_code'];
                $completedMetadata = $this->metadataEnricher->enrich(
                    $context,
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
                $completedMetadata = $this->metadataEnricher->enrich(
                    $context,
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
                $this->uploadArtifact($run);
                $this->dispatchCompletedEvent($context, $run, $exitCode);

                $this->logger->info('Completed Postgres operation', $this->logContext($context, $run, [
                    'exit_code' => $exitCode,
                ]));

                $context->result = DriverResult::success($output, $exitCode, $completedMetadata['metadata'] ?? []);

                return $context->result;
            }

            $this->logger->error('Postgres operation failed', $this->logContext($context, $run, [
                'exit_code' => $exitCode,
            ]));

            $context->result = DriverResult::failure($output, $exitCode, $completedMetadata['metadata'] ?? []);

            return $context->result;
        } catch (Throwable $exception) {
            $this->outputStore->discardCaptureSession($outputSession);

            $this->logger->error('Postgres operation crashed', $this->logContext($context, $run, [
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

        throw ConfigurationException::unsupportedOperation($operation, 'Postgres');
    }

    private function uploadArtifact(CommandRun $run): void
    {
        if ($run->operation === 'physical_backup') {
            $backupDirs = $this->filesystem->glob($this->config->physicalOutputDir.'/backup_*');
            $backupDirs = array_filter($backupDirs, $this->filesystem->isDirectory(...));

            if ($backupDirs === []) {
                return;
            }

            $artifactPath = Arr::last($backupDirs);
        } else {
            $artifactPath = $this->config->format === PostgresFormat::Directory
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
        $replication = $plannedMetadata['metadata']['replication'] ?? null;

        if (! is_array($replication)) {
            return $plannedMetadata;
        }

        foreach (['source', 'destination'] as $role) {
            if (isset($replication[$role]['redacted'])) {
                $replication[$role]['redacted'] = $this->commandLineRedactor->redact($replication[$role]['redacted']);
            }
        }

        $plannedMetadata['metadata']['replication'] = $replication;

        return $plannedMetadata;
    }

    private function mergeRestoreAuditMetadata(array $plannedMetadata, array $restoreAudit): array
    {
        if ($restoreAudit === []) {
            return $plannedMetadata;
        }

        $metadata = $plannedMetadata['metadata'] ?? [];

        return [
            ...$plannedMetadata,
            'metadata' => [
                ...$metadata,
                ...$restoreAudit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(DriverContext $context, CommandRun $run, array $extra = []): array
    {
        return collect([
            'run_id' => $run->getKey(),
            'operation' => $context->operation,
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

    private function dispatchCompletedEvent(DriverContext $context, CommandRun $run, int $exitCode): void
    {
        match ($context->operation) {
            'logical_backup' => $this->events->dispatch(new PostgresLogicalBackupCompleted(
                run: $run,
                artifactPath: $run->artifact_path ?? '',
                format: $this->config->format->value,
                exitCode: $exitCode,
            )),
            'logical_restore_latest', 'logical_restore_file' => $this->events->dispatch(new PostgresLogicalRestoreCompleted(
                run: $run,
                restoreTarget: $run->restore_target ?? '',
                format: $this->config->format->value,
                exitCode: $exitCode,
            )),
            'physical_backup' => $this->events->dispatch(new PostgresPhysicalBackupCompleted(
                run: $run,
                artifactPath: $run->artifact_path ?? '',
                walMethod: $this->config->physicalWalMethod->value,
                compression: $this->config->physicalCompression->value,
                exitCode: $exitCode,
            )),
            'replication_sync' => $this->events->dispatch(new PostgresReplicationCompleted(
                run: $run,
                result: $exitCode === 0 ? 'completed' : 'failed',
                engine: 'pgsql',
                exitCode: $exitCode,
            )),
            default => null,
        };
    }
}
