<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class MysqlDriver implements BackupDriver
{
    public function __construct(
        private readonly RestoreSafetyGuard $restoreSafetyGuard,
        private readonly CommandOutputStore $outputStore,
        private readonly CommandLineRedactor $commandLineRedactor,
        private readonly BackupArtifactUploader $artifactUploader,
        private readonly LoggerInterface $logger,
        private readonly MysqlConfiguration $config,
        private readonly MysqlProcessBuilder $processBuilder,
        private readonly MysqlMetadataBuilder $metadataBuilder,
        private readonly MysqlDrillExecutor $drillExecutor,
        private readonly MysqlPitrExecutor $pitrExecutor,
        private readonly MysqlRestoreExecutor $restoreExecutor,
        private readonly MysqlReplicationHandler $replicationHandler,
        private readonly MysqlCommandLineFormatter $commandLineFormatter,
        private readonly MysqlReplicationMetadataBuilder $replicationMetadata,
        private readonly MysqlProcessRunner $processRunner,
    ) {}

    public function execute(DriverContext $context, CommandRun $run): DriverResult
    {
        try {
            $run = $run->fresh() ?? $run;
            $plannedMetadata = $this->plannedMetadata($context, $run);
            $plannedMetadata = $this->replicationMetadata->redactReplicationMetadata($plannedMetadata);
            $restoreAudit = $this->restoreSafetyGuard->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = MysqlDriverLogContext::mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->commandLineRedactor->redact($this->commandLineFormatter->format($context, $run, $plannedMetadata));
            $persistedPlannedMetadata = $this->metadataBuilder->persistedPlannedMetadata($plannedMetadata);

            $run->forceFill(['command_line' => $displayCommandLine])->save();
            $run->recordMetadata($persistedPlannedMetadata);
            $run = $run->fresh() ?? $run;

            $this->logger->info('Starting mysql operation', MysqlDriverLogContext::build($context, $run, [
                'command_line' => $displayCommandLine,
            ], $this->replicationMetadata->countRestoreDecisionEvents($run)));

            $result = $this->executeOperation($context, $run, $plannedMetadata);
            $storedOutput = $this->outputStore->persist($run, $result['output']);
            $output = (string) ($storedOutput['command_output'] ?? '');
            $exitCode = (int) $result['exit_code'];
            $completedMetadata = $this->metadataBuilder->completedMetadata($context, $run, $plannedMetadata, $exitCode, [
                ...$result['metadata'],
                ...$storedOutput['metadata'],
            ]);

            $run->forceFill(['command_output' => $output, 'exit_code' => $exitCode])->save();
            $run->recordMetadata($completedMetadata);

            if ($exitCode === 0) {
                $this->uploadArtifact($run);
                $this->logger->info('Completed mysql operation', MysqlDriverLogContext::build($context, $run, [
                    'exit_code' => $exitCode,
                ], $this->replicationMetadata->countRestoreDecisionEvents($run)));

                $context->result = DriverResult::success($output, $exitCode, $completedMetadata['metadata'] ?? []);

                return $context->result;
            }

            $this->logger->error('mysql operation failed', MysqlDriverLogContext::build($context, $run, [
                'exit_code' => $exitCode,
            ], $this->replicationMetadata->countRestoreDecisionEvents($run)));

            $context->result = DriverResult::failure($output, $exitCode, $completedMetadata['metadata'] ?? []);

            return $context->result;
        } catch (Throwable $exception) {
            $this->logger->error('mysql operation crashed', MysqlDriverLogContext::build($context, $run, [
                'error' => $exception->getMessage(),
            ], $this->replicationMetadata->countRestoreDecisionEvents($run)));

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeOperation(DriverContext $context, CommandRun $run, array $plannedMetadata): array
    {
        return match ($context->operation) {
            'logical_backup' => $this->processRunner->run($this->processBuilder->buildProcess($context, $run, $plannedMetadata)),
            'logical_restore_file', 'logical_restore_latest' => $this->restoreExecutor->execute(
                $this->restoreExecutor->resolveProcess($context, $run, $plannedMetadata),
                $run,
                $plannedMetadata,
            ),
            'pitr_restore' => $this->pitrExecutor->execute($context, $run, $plannedMetadata),
            'replication_sync' => $this->replicationHandler->executeReplicationSync($run, $plannedMetadata),
            'backup_drill' => $this->drillExecutor->execute($context, $this->processBuilder->buildProcess($context, $run, $plannedMetadata), $run, $plannedMetadata),
            default => throw ConfigurationException::unsupportedOperation($context->operation, 'mysql'),
        };
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function buildProcess(DriverContext $context, CommandRun $run, array $plannedMetadata = []): Process
    {
        if (collect(['logical_restore_file', 'logical_restore_latest'])->containsStrict($context->operation)) {
            return $this->restoreExecutor->resolveProcess($context, $run, $plannedMetadata);
        }

        return $this->processBuilder->buildProcess($context, $run, $plannedMetadata);
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function plannedMetadata(DriverContext $context, CommandRun $run): array
    {
        $metadata = $this->metadataBuilder->plannedMetadata($context, $run);

        if ($context->operation === 'replication_sync') {
            $metadata['metadata']['replication'] = $this->replicationMetadata->buildPlan(
                $this->replicationHandler->replicationPayload($run),
                $run,
            );
        }

        return $metadata;
    }

    private function uploadArtifact(CommandRun $run): void
    {
        $results = $this->artifactUploader->upload($this->config->backupTarget($run));

        if ($results !== []) {
            $run->recordMetadata(['metadata' => ['uploads' => $results]]);
        }
    }
}
