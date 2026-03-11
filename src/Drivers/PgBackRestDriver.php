<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
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
final class PgBackRestDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        try {
            $process = $this->buildProcess($run);

            $run->markAsRunning();
            $run->forceFill([
                'command_line' => $process->getCommandLine(),
            ])->save();

            event(new BackupStarted($run));

            $this->logger()->info('Starting pgBackRest operation', [
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

                $this->logger()->info('Completed pgBackRest operation', [
                    'run_id' => $run->getKey(),
                    'operation' => $run->operation,
                    'exit_code' => $exitCode,
                ]);

                return;
            }

            $run->markAsFailed($exitCode, $output);
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('pgBackRest operation failed', [
                'run_id' => $run->getKey(),
                'operation' => $run->operation,
                'exit_code' => $exitCode,
            ]);
        } catch (Throwable $exception) {
            $run->markAsFailed(output: $exception->getMessage());
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('pgBackRest operation crashed', [
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
        $binary = (string) config('checkpoint.drivers.pgbackrest.binary', 'pgbackrest');

        if ($binary === '') {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.binary must be a non-empty string.');
        }

        $definition = $this->operationDefinition($run->operation);
        $argv = [
            $binary,
            $definition['command'],
            ...$this->commonOptions($run->operation),
            ...$definition['options'],
            ...$this->restoreOptions($run),
            ...$this->extraArgs($definition['extra_args_key']),
        ];

        return new Process(
            $argv,
            timeout: (float) config('checkpoint.drivers.pgbackrest.command_timeout_seconds', 7200),
        );
    }

    /**
     * @return array{command:string,options:list<string>,extra_args_key:string}
     */
    private function operationDefinition(string $operation): array
    {
        return match ($operation) {
            'pgbackrest_backup_full' => [
                'command' => 'backup',
                'options' => ['--type=full'],
                'extra_args_key' => 'backup',
            ],
            'pgbackrest_backup_diff' => [
                'command' => 'backup',
                'options' => ['--type=diff'],
                'extra_args_key' => 'backup',
            ],
            'pgbackrest_backup_incr' => [
                'command' => 'backup',
                'options' => ['--type=incr'],
                'extra_args_key' => 'backup',
            ],
            'pgbackrest_restore' => [
                'command' => 'restore',
                'options' => $this->restoreModeOptions(),
                'extra_args_key' => 'restore',
            ],
            'pgbackrest_verify' => [
                'command' => 'verify',
                'options' => [],
                'extra_args_key' => 'verify',
            ],
            'pgbackrest_check' => [
                'command' => 'check',
                'options' => [],
                'extra_args_key' => 'check',
            ],
            'pgbackrest_info' => [
                'command' => 'info',
                'options' => [],
                'extra_args_key' => 'info',
            ],
            default => throw new ConfigurationException(
                sprintf('Unsupported pgBackRest operation [%s].', $operation),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function commonOptions(string $operation): array
    {
        $options = [];
        $stanza = (string) config('checkpoint.drivers.pgbackrest.stanza', 'main');
        $repo = config('checkpoint.drivers.pgbackrest.repo');
        $processMax = (int) config('checkpoint.drivers.pgbackrest.process_max', 1);

        if ($stanza !== '') {
            $options[] = '--stanza='.$stanza;
        }

        if (is_int($repo) || (is_string($repo) && $repo !== '')) {
            $options[] = '--repo='.$repo;
        }

        if ($processMax > 0) {
            $options[] = '--process-max='.$processMax;
        }

        if ($operation !== 'pgbackrest_restore') {
            if ((bool) config('checkpoint.drivers.pgbackrest.resume', true)) {
                $options[] = '--resume';
            }

            if ((bool) config('checkpoint.drivers.pgbackrest.start_fast', true)) {
                $options[] = '--start-fast';
            }

            if ((bool) config('checkpoint.drivers.pgbackrest.backup_standby', false)) {
                $options[] = '--backup-standby';
            }

            if ((bool) config('checkpoint.drivers.pgbackrest.checksum_page', false)) {
                $options[] = '--checksum-page';
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function restoreModeOptions(): array
    {
        $options = [];

        if ((bool) config('checkpoint.drivers.pgbackrest.delta', false)) {
            $options[] = '--delta';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function restoreOptions(CommandRun $run): array
    {
        $argument = trim((string) ($run->argument_text ?? ''));

        if ($run->operation !== 'pgbackrest_restore' || $argument === '') {
            return [];
        }

        return ['--set='.$argument];
    }

    /**
     * @return list<string>
     */
    private function extraArgs(string $key): array
    {
        $value = config("checkpoint.drivers.pgbackrest.extra_args.{$key}", []);

        if (! is_array($value)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.pgbackrest.extra_args.%s must be an array.', $key),
            );
        }

        $args = array_values(array_filter(
            $value,
            static fn (mixed $arg): bool => is_string($arg) && $arg !== '',
        ));

        /** @var list<string> $args */
        return $args;
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
