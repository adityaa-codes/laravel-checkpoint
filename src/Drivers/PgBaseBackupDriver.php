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
use AdityaaCodes\LaravelCheckpoint\Services\BackupArtifactUploader;
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\PostRestoreVerificationBuilder;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class PgBaseBackupDriver implements BackupDriver
{
    /** @var array<string, int>|null */
    private ?array $storedOutputMetadata = null;

    private ?array $outputSession = null;

    public function execute(CommandRun $run): void
    {
        $this->storedOutputMetadata = null;
        $this->outputSession = null;

        try {
            if (! $run->claimPendingExecution()) {
                return;
            }

            $run = $run->fresh() ?? $run;
            $plannedMetadata = $this->plannedMetadata($run);
            $restoreAudit = $this->restoreSafetyGuard()->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->commandLineRedactor()->redact($this->buildProcess($run)->getCommandLine());
            $persistedPlannedMetadata = $this->persistedPlannedMetadata($plannedMetadata);

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata($persistedPlannedMetadata);
            $run = $run->fresh() ?? $run;

            event(new BackupStarted($run));

            $this->logger()->info('Starting pg_basebackup operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $process = $this->buildProcess($run);
            $this->outputSession = $this->outputStore()->startCapture($run);

            $capturedOutput = $this->outputCapture()->captureProcess(
                $process,
                fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, $this->outputSession, $chunk),
            );

            $output = $capturedOutput['output'];
            $exitCode = $process->getExitCode() ?? -1;

            $storedOutput = $this->outputStore()->finishCapture($run, $output, $this->outputSession);
            $this->outputSession = null;
            $this->storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
            $output = $storedOutput['command_output'];

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

                $this->uploadArtifact($run);

                event(new BackupCompleted($run, $exitCode, $output));

                $this->logger()->info('Completed pg_basebackup operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $output);
            $run = $run->fresh() ?? $run;

            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('pg_basebackup operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            if (is_array($this->storedOutputMetadata)) {
                $this->outputStore()->cleanupMetadata($this->storedOutputMetadata);
            }

            $this->outputStore()->discardCaptureSession($this->outputSession);

            $run->markAsFailed(output: $exception->getMessage());
            $run = $run->fresh() ?? $run;

            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('pg_basebackup operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            report($exception);

            throw $exception;
        }
    }

    private function buildProcess(CommandRun $run): Process
    {
        $timeout = $this->timeout();

        return match ($run->operation) {
            'physical_backup' => new Process(
                $this->backupArgs(),
                timeout: $timeout,
            ),
            'physical_restore' => new Process(
                $this->restoreArgs($run),
                timeout: $timeout,
            ),
            default => throw new ConfigurationException(
                sprintf('Unsupported pg_basebackup operation [%s].', $run->operation),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function backupArgs(): array
    {
        $dir = $this->outputDir();
        $timestamp = now()->format('Ymd_His');

        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0700, true, true);
        }

        return [
            $this->binary(),
            '-D', $dir.'/backup_'.$timestamp,
            '-Ft',
            '-z',
            '-X', 'stream',
            '-P',
        ];
    }

    /**
     * @return list<string>
     */
    private function restoreArgs(CommandRun $run): array
    {
        $target = trim((string) ($run->argument_text ?? ''));

        if ($target === '') {
            throw new ConfigurationException('physical_restore requires a backup directory path as argument.');
        }

        if (! is_dir($target) || ! is_file($target.'/base.tar.gz')) {
            throw new ConfigurationException(
                sprintf('Physical backup target [%s] does not contain base.tar.gz.', $target),
            );
        }

        return ['echo', 'pg_basebackup tar archive ready at: '.$target.' — extract with: tar -xzf '.$target.'/base.tar.gz -C /path/to/pgdata'];
    }

    /**
     * @return array<string, mixed>
     */
    private function plannedMetadata(CommandRun $run): array
    {
        return [
            'driver' => 'pgbasebackup',
            'operation' => $run->operation,
            'argument' => $run->argument_text,
            'metadata' => [
                'binary' => $this->binary(),
                'output_dir' => $this->outputDir(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function persistedPlannedMetadata(array $plannedMetadata): array
    {
        return array_filter($plannedMetadata, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
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
                'restore_audit' => $restoreAudit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode, array $extra): array
    {
        return [
            ...$plannedMetadata,
            'metadata' => [
                ...($plannedMetadata['metadata'] ?? []),
                'exit_code' => $exitCode,
                'restore_decision_event_count' => $this->restoreDecisionEventCount($run),
                ...$extra,
            ],
        ];
    }

    private function binary(): string
    {
        return (string) config('checkpoint.drivers.pgbasebackup.binary', 'pg_basebackup');
    }

    private function outputDir(): string
    {
        return (string) config('checkpoint.drivers.pgbasebackup.output_dir', storage_path('app/checkpoint/basebackups'));
    }

    private function timeout(): int
    {
        return max(1, (int) config('checkpoint.drivers.pgbasebackup.timeout', 3600));
    }

    private function commandLineRedactor(): CommandLineRedactor
    {
        return resolve(CommandLineRedactor::class);
    }

    private function logger(): LoggerInterface
    {
        return Log::channel((string) config('checkpoint.log_channel', 'stack'));
    }

    private function outputCapture(): CommandOutputCapture
    {
        return resolve(CommandOutputCapture::class);
    }

    private function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }

    private function restoreSafetyGuard(): RestoreSafetyGuard
    {
        return resolve(RestoreSafetyGuard::class);
    }

    private function postRestoreVerificationBuilder(): PostRestoreVerificationBuilder
    {
        return resolve(PostRestoreVerificationBuilder::class);
    }

    private function restoreDecisionEventCount(CommandRun $run): int
    {
        return RestoreDecisionEvent::query()
            ->where('command_run_id', (int) $run->getKey())
            ->count();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function logContext(CommandRun $run, array $context = []): array
    {
        return [
            'run_id' => (int) $run->getKey(),
            'operation' => $run->operation,
            ...$context,
        ];
    }

    private function tapCapturedOutput(CommandRun $run, ?array $outputSession, string $chunk): void
    {
        $this->outputStore()->tapCaptureStream($run, $outputSession, $chunk);
    }

    private function uploadArtifact(CommandRun $run): void
    {
        $backupDirs = glob($this->outputDir().'/backup_*', GLOB_ONLYDIR);

        if ($backupDirs === false || $backupDirs === []) {
            return;
        }

        $artifactPath = $backupDirs[array_key_last($backupDirs)];

        $results = (new BackupArtifactUploader)->upload($artifactPath);

        if ($results !== []) {
            $run->recordMetadata([
                'metadata' => [
                    'uploads' => $results,
                ],
            ]);
        }
    }
}
