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
            $normalizedOutput = $this->normalizeOutput(
                $run->operation,
                $output,
                $exitCode,
            );

            $run->forceFill([
                'command_output' => $normalizedOutput,
                'exit_code' => $exitCode,
            ])->save();

            if ($exitCode === 0) {
                $run->markAsSucceeded($exitCode, $normalizedOutput);

                event(new BackupCompleted($run, $exitCode, $normalizedOutput));

                $this->logger()->info('Completed pgBackRest operation', [
                    'run_id' => $run->getKey(),
                    'operation' => $run->operation,
                    'exit_code' => $exitCode,
                ]);

                return;
            }

            $run->markAsFailed($exitCode, $normalizedOutput);
            event(new BackupFailed($run, $exitCode, $normalizedOutput));

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
                'options' => ['--output=json'],
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

    private function normalizeOutput(string $operation, string $rawOutput, int $exitCode): string
    {
        $summary = match ($operation) {
            'pgbackrest_info' => $this->parseInfoSummary($rawOutput),
            'pgbackrest_check' => $this->parseCheckSummary($rawOutput, $exitCode),
            default => null,
        };

        if ($summary === null) {
            return $rawOutput;
        }

        $encodedSummary = json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (! is_string($encodedSummary)) {
            return $rawOutput;
        }

        $trimmedOutput = trim($rawOutput);

        if ($trimmedOutput === '') {
            return "[checkpoint-summary]\n".$encodedSummary;
        }

        return "[checkpoint-summary]\n".$encodedSummary."\n\n[raw-output]\n".$trimmedOutput;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseInfoSummary(string $output): ?array
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return null;
        }

        $stanzas = [];

        foreach (array_values($decoded) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $backupEntries = array_values(array_filter(
                $entry['backup'] ?? [],
                is_array(...),
            ));
            $latestBackup = $backupEntries === [] ? null : $backupEntries[array_key_last($backupEntries)];
            $summary = [
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : 'unknown',
                'status_code' => is_int($entry['status']['code'] ?? null) ? $entry['status']['code'] : null,
                'status_message' => is_string($entry['status']['message'] ?? null) ? $entry['status']['message'] : null,
                'backup_count' => count($backupEntries),
            ];

            if (is_array($latestBackup)) {
                $summary['latest_backup'] = array_filter([
                    'label' => is_string($latestBackup['label'] ?? null) ? $latestBackup['label'] : null,
                    'type' => is_string($latestBackup['type'] ?? null) ? $latestBackup['type'] : null,
                    'repository_size' => is_int($latestBackup['info']['repository']['size'] ?? null)
                        ? $latestBackup['info']['repository']['size']
                        : null,
                    'timestamp_stop' => is_int($latestBackup['timestamp']['stop'] ?? null)
                        ? $latestBackup['timestamp']['stop']
                        : null,
                ], static fn (mixed $value): bool => $value !== null);
            }

            $stanzas[] = $summary;
        }

        return [
            'format' => 'pgbackrest-info-v1',
            'stanza_count' => count($stanzas),
            'stanzas' => $stanzas,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCheckSummary(string $output, int $exitCode): ?array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/u', trim($output)) ?: [],
            static fn (string $line): bool => $line !== '',
        ));

        if ($lines === []) {
            return null;
        }

        $stanza = null;
        $infoCount = 0;
        $warningCount = 0;
        $errorCount = 0;

        foreach ($lines as $line) {
            if (preg_match('/\bINFO\b/', $line) === 1) {
                $infoCount++;
            }

            if (preg_match('/\bWARN(?:ING)?\b/', $line) === 1) {
                $warningCount++;
            }

            if (preg_match('/\bERROR\b/', $line) === 1) {
                $errorCount++;
            }

            if ($stanza === null && preg_match('/stanza[^a-z0-9_-]*([a-z0-9_-]+)/i', $line, $matches) === 1) {
                $stanza = $matches[1];
            }
        }

        return [
            'format' => 'pgbackrest-check-v1',
            'ok' => $exitCode === 0 && $errorCount === 0,
            'line_count' => count($lines),
            'info_count' => $infoCount,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'stanza' => $stanza,
        ];
    }

    private function logger(): LoggerInterface
    {
        return Log::channel(config('checkpoint.log_channel', 'stack'));
    }
}
