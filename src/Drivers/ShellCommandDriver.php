<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverResult;
use Psr\Log\LoggerInterface;
use Throwable;

/** @internal */
final class ShellCommandDriver implements BackupDriver
{
    public function __construct(
        private readonly ShellCommandConfig $shellConfig,
        private readonly ShellCommandProcessBuilder $processBuilder,
        private readonly ShellCommandMetadataBuilder $metadataBuilder,
        private readonly ShellCommandSnapshotRunner $snapshotRunner,
        private readonly CommandOutputCapture $outputCapture,
        private readonly CommandOutputStore $outputStore,
        private readonly CommandLineRedactor $commandLineRedactor,
        private readonly PostRestoreVerificationBuilder $postRestoreVerificationBuilder,
        private readonly RestoreSafetyGuard $restoreSafetyGuard,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(DriverContext $context, CommandRun $run): DriverResult
    {
        $storedOutputMetadata = null;
        $outputSession = null;

        try {
            $run = $run->fresh() ?? $run;

            if ($this->shouldCreatePreRestoreSnapshot($context, $run)) {
                $snapshotRun = $this->createSnapshotRun($run);
                $this->snapshotRunner->run($snapshotRun);
                $snapshotRun = $snapshotRun->fresh() ?? $snapshotRun;

                if ($snapshotRun->status === CommandRunStatus::Failed) {
                    throw new ConfigurationException(__('messages.errors.pre_restore_failed'));
                }
            }

            $process = $this->processBuilder->buildProcess($context, $run);
            $plannedMetadata = $this->metadataBuilder->plannedMetadata($context, $run);
            $restoreAudit = $this->restoreSafetyGuard->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->metadataBuilder->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->commandLineRedactor->redact($process->getCommandLine());

            $run->forceFill(['command_line' => $displayCommandLine])->save();
            $run->recordMetadata($plannedMetadata);
            $run = $run->fresh() ?? $run;

            $this->logger->info('Starting checkpoint operation', $this->logContext($context, $run, [
                'command_line' => $displayCommandLine,
            ]));

            $outputSession = $this->outputStore->startCapture($run);
            $capturedOutput = $this->outputCapture->captureProcess(
                $process,
                fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, $outputSession, $chunk),
            );
            $storedOutput = $this->outputStore->finishCapture($run, $capturedOutput['output'], $outputSession);
            $outputSession = null;
            $storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
            $output = $storedOutput['command_output'];
            $exitCode = $process->getExitCode() ?? -1;
            $completedMetadata = $this->metadataBuilder->completedMetadata(
                $context,
                $run,
                $plannedMetadata,
                $exitCode,
                [
                    ...$capturedOutput['metadata'],
                    ...$storedOutput['metadata'],
                ],
                $this->postRestoreVerificationBuilder,
            );

            $run->forceFill(['command_output' => $output, 'exit_code' => $exitCode])->save();
            $run->recordMetadata($completedMetadata);

            if ($exitCode === 0) {
                $this->logger->info('Completed checkpoint operation', $this->logContext($context, $run, [
                    'exit_code' => $exitCode,
                ]));

                $context->result = DriverResult::success($output, $exitCode, $completedMetadata['metadata'] ?? []);

                return $context->result;
            }

            $this->logger->error('Checkpoint operation failed', $this->logContext($context, $run, [
                'exit_code' => $exitCode,
            ]));

            $context->result = DriverResult::failure($output, $exitCode, $completedMetadata['metadata'] ?? []);

            return $context->result;
        } catch (Throwable $exception) {
            if (is_array($storedOutputMetadata)) {
                $this->outputStore->cleanupMetadata($storedOutputMetadata);
            }

            $this->outputStore->discardCaptureSession($outputSession);

            $this->logger->error('Checkpoint operation crashed', $this->logContext($context, $run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    private function shouldCreatePreRestoreSnapshot(DriverContext $context, CommandRun $run): bool
    {
        if (! $this->shellConfig->preRestoreSnapshot()) {
            return false;
        }

        return collect([
            'logical_restore_latest',
            'logical_restore_file',
            'pitr_restore',
        ])->containsStrict($context->operation);
    }

    private function createSnapshotRun(CommandRun $run): CommandRun
    {
        return CommandRun::query()->create([
            'operation' => 'logical_backup',
            'argument_text' => null,
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
            'requested_by_type' => $run->getAttribute('requested_by_type'),
            'requested_by_id' => $run->getAttribute('requested_by_id'),
        ]);
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
            'driver' => 'shell',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            'restore_decision_event_count' => $this->restoreDecisionEventCount($run),
            ...$extra,
        ])->filter(static fn (mixed $value): bool => $value !== null)->all();
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}|null  $outputSession
     */
    private function tapCapturedOutput(CommandRun $run, ?array $outputSession, string $chunk): void
    {
        $this->outputStore->appendCaptureChunk($outputSession, $chunk);
        $run->recordHeartbeatIfDue();
    }

    private function restoreDecisionEventCount(CommandRun $run): ?int
    {
        if (! collect(['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore'])->containsStrict($run->operation)) {
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
