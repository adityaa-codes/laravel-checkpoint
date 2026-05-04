<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class ShellCommandDriver implements BackupDriver
{
    private bool $failureEventDispatched = false;

    public function execute(CommandRun $run): void
    {
        $this->failureEventDispatched = false;

        if ($this->shouldCreatePreRestoreSnapshot($run)) {
            $snapshotRun = $this->createSnapshotRun($run);
            $this->runProcess($snapshotRun);
            $snapshotRun = $snapshotRun->fresh() ?? $snapshotRun;

            if ($snapshotRun->status === CommandRunStatus::Failed) {
                $message = __('messages.errors.pre_restore_failed');

                $run->markAsFailed(output: $message);
                $this->dispatchBackupFailed($run, -1, $message);

                $this->logger()->error('Pre-restore snapshot failed', [
                    'run_id' => $run->getKey(),
                    'snapshot_run_id' => $snapshotRun->getKey(),
                    'output' => $snapshotRun->command_output,
                ]);

                return;
            }
        }

        $this->runProcess($run);
    }

    private function runProcess(CommandRun $run): void
    {
        $storedOutputMetadata = null;
        $outputSession = null;

        try {
            if (! $run->claimPendingExecution()) {
                return;
            }

            $process = $this->buildProcess($run);
            $plannedMetadata = $this->plannedMetadata($run);
            $restoreAudit = $this->restoreSafetyGuard()->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->redactCommandLine($process->getCommandLine());

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata($plannedMetadata);
            $run = $run->fresh() ?? $run;

            event(new BackupStarted($run));

            $this->logger()->info('Starting checkpoint operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $outputSession = $this->outputStore()->startCapture($run);
            $capturedOutput = $this->outputCapture()->captureProcess(
                $process,
                fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, $outputSession, $chunk),
            );
            $storedOutput = $this->outputStore()->finishCapture($run, $capturedOutput['output'], $outputSession);
            $outputSession = null;
            $storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
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

            $run->forceFill([
                'command_output' => $output,
                'exit_code' => $exitCode,
            ])->save();
            $run->recordMetadata($completedMetadata);

            if ($exitCode === 0) {
                $run->markAsSucceeded($exitCode, $output);
                $run = $run->fresh() ?? $run;

                event(new BackupCompleted($run, $exitCode, $output));

                $this->logger()->info('Completed checkpoint operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $output);
            $run = $run->fresh() ?? $run;
            $this->dispatchBackupFailed($run, $exitCode, $output);

            $this->logger()->error('Checkpoint operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            if (is_array($storedOutputMetadata)) {
                $this->outputStore()->cleanupMetadata($storedOutputMetadata);
            }

            $this->outputStore()->discardCaptureSession($outputSession);

            $run->markAsFailed(output: $exception->getMessage());
            $run = $run->fresh() ?? $run;
            $this->dispatchBackupFailed($run, -1, $exception->getMessage(), $exception);

            $this->logger()->error('Checkpoint operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    private function buildProcess(CommandRun $run): Process
    {
        $template = config("checkpoint.drivers.shell.commands.{$run->operation}", '');

        if (is_array($template)) {
            if ($template === []) {
                throw new ConfigurationException(
                    sprintf('No shell command configured for operation [%s].', $run->operation),
                );
            }

            $argv = $this->substitutePlaceholders($template, $run);
        } elseif (is_string($template) && trim($template) !== '') {
            $tokens = preg_split('/\s+/', trim($template));

            if ($tokens === false || $tokens === []) {
                throw new ConfigurationException(
                    sprintf('Invalid shell command template for operation [%s].', $run->operation),
                );
            }

            $argv = $this->substitutePlaceholders($tokens, $run);
        } else {
            throw new ConfigurationException(
                sprintf('No shell command configured for operation [%s].', $run->operation),
            );
        }

        return new Process(
            $argv,
            timeout: (float) config('checkpoint.drivers.shell.command_timeout_seconds', 7200),
        );
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function substitutePlaceholders(array $argv, CommandRun $run): array
    {
        $backupDir = (string) config('checkpoint.drivers.shell.backup_dir', storage_path('db-backups'));
        $backupPrefix = (string) config('checkpoint.drivers.shell.backup_prefix', 'backup');
        $argument = (string) ($run->argument_text ?? '');

        $replacements = [
            '{db}' => (string) config('database.connections.'.config('database.default').'.database', ''),
            '{target}' => $argument,
            '{output}' => $backupDir.'/'.$backupPrefix.'-'.$run->getKey().'.sql',
            '{file}' => $argument,
            '{backup_dir}' => $backupDir,
        ];

        return array_map(
            static fn (string $token): string => str_replace(
                array_keys($replacements),
                array_values($replacements),
                $token,
            ),
            $argv,
        );
    }

    private function shouldCreatePreRestoreSnapshot(CommandRun $run): bool
    {
        if (! (bool) config('checkpoint.drivers.shell.pre_restore_snapshot', true)) {
            return false;
        }

        return in_array($run->operation, [
            'logical_restore_latest',
            'logical_restore_file',
            'pitr_restore',
        ], true);
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

    private function logger(): LoggerInterface
    {
        return Log::channel(config('checkpoint.log_channel', 'stack'));
    }

    private function restoreSafetyGuard(): RestoreSafetyGuard
    {
        return resolve(RestoreSafetyGuard::class);
    }

    private function redactCommandLine(string $commandLine): string
    {
        return $this->commandLineRedactor()->redact($commandLine);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $extra = []): array
    {
        return array_filter([
            'run_id' => $run->getKey(),
            'operation' => $run->operation,
            'driver' => 'shell',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            'restore_decision_event_count' => $this->restoreDecisionEventCount($run),
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function plannedMetadata(CommandRun $run): array
    {
        return match ($run->operation) {
            'logical_backup' => [
                'backup_type' => 'logical_export',
                'artifact_path' => config('checkpoint.drivers.shell.backup_dir', storage_path('db-backups'))
                    .'/'.config('checkpoint.drivers.shell.backup_prefix', 'backup')
                    .'-'.$run->getKey().'.sql',
                'verification_state' => 'not_applicable',
                'metadata' => ['driver' => 'shell'],
            ],
            'logical_restore_latest', 'logical_restore_file', 'pitr_restore' => [
                'restore_target' => $run->argument_text,
                'metadata' => ['driver' => 'shell'],
            ],
            default => ['metadata' => ['driver' => 'shell']],
        };
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $captureMetadata
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = $plannedMetadata['metadata'] ?? ['driver' => 'shell'];

        if (! is_array($metadata)) {
            $metadata = ['driver' => 'shell'];
        }

        $completed = [
            'metadata' => [
                ...$metadata,
                ...$captureMetadata,
            ],
        ];

        if ($run->operation === 'logical_backup' && $exitCode === 0) {
            $completed['last_known_good_at'] = now();
        }

        $postRestoreVerification = $this->postRestoreVerificationBuilder()->build(
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

    private function outputCapture(): CommandOutputCapture
    {
        return resolve(CommandOutputCapture::class);
    }

    private function commandLineRedactor(): CommandLineRedactor
    {
        return resolve(CommandLineRedactor::class);
    }

    private function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }

    private function postRestoreVerificationBuilder(): PostRestoreVerificationBuilder
    {
        return resolve(PostRestoreVerificationBuilder::class);
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}|null  $outputSession
     */
    private function tapCapturedOutput(CommandRun $run, ?array $outputSession, string $chunk): void
    {
        $this->outputStore()->appendCaptureChunk($outputSession, $chunk);
        $run->recordHeartbeatIfDue();
    }

    private function restoreDecisionEventCount(CommandRun $run): ?int
    {
        if (! in_array($run->operation, ['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore'], true)) {
            return null;
        }

        if (! $run->exists) {
            return null;
        }

        return RestoreDecisionEvent::query()
            ->where('command_run_id', (int) $run->getKey())
            ->count();
    }

    private function dispatchBackupFailed(CommandRun $run, int $exitCode, string $output, ?Throwable $exception = null): void
    {
        if ($this->failureEventDispatched) {
            return;
        }

        $this->failureEventDispatched = true;
        event(new BackupFailed($run, $exitCode, $output, $exception));
    }
}
