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
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
class ShellCommandDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        if ($this->shouldCreatePreRestoreSnapshot($run)) {
            $snapshotRun = $this->createSnapshotRun($run);
            $this->runProcess($snapshotRun);

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

            $run->markAsRunning();
            $run->forceFill([
                'command_line' => $process->getCommandLine(),
            ])->save();

            event(new BackupStarted($run));

            $this->logger()->info('Starting checkpoint operation', [
                'run_id' => $run->getKey(),
                'operation' => $run->operation,
                'command_line' => $run->command_line,
            ]);

            $process->run();

            $output = $this->combinedOutput($process);
            $exitCode = $process->getExitCode() ?? -1;

            $run->forceFill([
                'command_output' => $output,
                'exit_code' => $exitCode,
            ])->save();

            if ($exitCode === 0) {
                $run->markAsSucceeded($exitCode, $output);

                event(new BackupCompleted($run, $exitCode, $output));

                $this->logger()->info('Completed checkpoint operation', [
                    'run_id' => $run->getKey(),
                    'operation' => $run->operation,
                    'exit_code' => $exitCode,
                ]);

                return;
            }

            $run->markAsFailed($exitCode, $output);
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('Checkpoint operation failed', [
                'run_id' => $run->getKey(),
                'operation' => $run->operation,
                'exit_code' => $exitCode,
            ]);
        } catch (Throwable $exception) {
            $run->markAsFailed(output: $exception->getMessage());
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('Checkpoint operation crashed', [
                'run_id' => $run->getKey(),
                'operation' => $run->operation,
                'error' => $exception->getMessage(),
            ]);

            if ($exception instanceof ConfigurationException) {
                throw $exception;
            }
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
}
