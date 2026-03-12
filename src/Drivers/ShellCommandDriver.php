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
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class ShellCommandDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        $this->restoreSafetyGuard()->ensureSafe($run, $this->plannedMetadata($run));

        if ($this->shouldCreatePreRestoreSnapshot($run)) {
            $snapshotRun = $this->createSnapshotRun($run);
            $this->runProcess($snapshotRun);
            $snapshotRun = $snapshotRun->fresh() ?? $snapshotRun;

            if ($snapshotRun->status === CommandRunStatus::Failed) {
                $message = __('messages.errors.pre_restore_failed');

                $run->markAsFailed(output: $message);
                event(new BackupFailed($run, -1, $message));

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
        try {
            $process = $this->buildProcess($run);
            $plannedMetadata = $this->plannedMetadata($run);
            $displayCommandLine = $this->redactCommandLine($process->getCommandLine());

            if (! $run->claimPendingExecution()) {
                return;
            }

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata($plannedMetadata);
            $run = $run->fresh() ?? $run;

            event(new BackupStarted($run));

            $this->logger()->info('Starting checkpoint operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $process->run();

            $output = $this->combinedOutput($process);
            $exitCode = $process->getExitCode() ?? -1;
            $completedMetadata = $this->completedMetadata($run, $plannedMetadata, $exitCode);

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
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('Checkpoint operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            $run->markAsFailed(output: $exception->getMessage());
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('Checkpoint operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    private function buildProcess(CommandRun $run): Process
    {
        $template = (string) config("checkpoint.drivers.shell.commands.{$run->operation}", '');

        if ($template === '') {
            throw new ConfigurationException(
                sprintf('No shell command configured for operation [%s].', $run->operation),
            );
        }

        $argv = preg_split('/\s+/', trim($template));

        if ($argv === false || $argv === []) {
            throw new ConfigurationException(
                sprintf('Invalid shell command template for operation [%s].', $run->operation),
            );
        }

        return new Process(
            $this->substitutePlaceholders($argv, $run),
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
            '{stanza}' => (string) config('checkpoint.drivers.shell.pgbackrest_stanza', 'main'),
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

    private function combinedOutput(Process $process): string
    {
        return trim($process->getOutput()."\n".$process->getErrorOutput());
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
        $patterns = [
            '/(\b(?:password|pass|token|secret|apikey|api_key|pgpassword)=)([^\s]+)/i',
            '/(--(?:password|pass|token|secret|apikey|api-key)=)([^\s]+)/i',
            '/(postgres(?:ql)?:\/\/[^:\s]+:)([^@\/\s]+)(@)/i',
        ];

        foreach ($patterns as $pattern) {
            $commandLine = (string) preg_replace($pattern, '$1[REDACTED]$3', $commandLine);
        }

        return $commandLine;
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
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode): array
    {
        $completed = [
            'metadata' => $plannedMetadata['metadata'] ?? ['driver' => 'shell'],
        ];

        if ($run->operation === 'logical_backup' && $exitCode === 0) {
            $completed['last_known_good_at'] = now();
        }

        return $completed;
    }
}
