<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputCapture;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class PgBackRestDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        $tempConfigPath = null;
        $storedOutputMetadata = null;
        $outputSession = null;

        try {
            $process = $this->buildProcess($run);
            $tempConfigPath = $this->tempConfigPath($process);
            $plannedMetadata = $this->plannedMetadata($run);
            $restoreAudit = $this->restoreSafetyGuard()->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
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

            $this->logger()->info('Starting pgBackRest operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $outputSession = $this->outputStore()->startCapture($run);
            $capturedOutput = $this->outputCapture()->captureProcess(
                $process,
                fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, $outputSession, $chunk),
            );
            $output = $capturedOutput['output'];
            $exitCode = $process->getExitCode() ?? -1;
            $summary = $this->operationSummary($run->operation, $output, $exitCode);
            $normalizedOutput = $this->normalizeOutput($summary, $output);
            $storedOutput = $this->outputStore()->finishCapture($run, $normalizedOutput, $outputSession);
            $outputSession = null;
            $storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
            $normalizedOutput = $storedOutput['command_output'];
            $completedMetadata = $this->completedMetadata(
                $run,
                $plannedMetadata,
                $summary,
                $exitCode,
                [
                    ...$capturedOutput['metadata'],
                    ...$storedOutput['metadata'],
                ],
            );

            $run->forceFill([
                'command_output' => $normalizedOutput,
                'exit_code' => $exitCode,
            ])->save();
            $run->recordMetadata($completedMetadata);

            if ($exitCode === 0) {
                $run->markAsSucceeded($exitCode, $normalizedOutput);
                $run = $run->fresh() ?? $run;

                event(new BackupCompleted($run, $exitCode, $normalizedOutput));

                $this->logger()->info('Completed pgBackRest operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $normalizedOutput);
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, $exitCode, $normalizedOutput));

            $this->logger()->error('pgBackRest operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            if (is_array($storedOutputMetadata)) {
                $this->outputStore()->cleanupMetadata($storedOutputMetadata);
            }

            $this->outputStore()->discardCaptureSession($outputSession);

            $run->markAsFailed(output: $exception->getMessage());
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('pgBackRest operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        } finally {
            $this->cleanupTempConfig($tempConfigPath);
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
            ...$this->repositoryOptions(),
            ...$definition['options'],
            ...$this->restoreOptions($run),
            ...$this->extraArgs($definition['extra_args_key']),
        ];

        $tempConfig = $this->secretConfigOptions();
        $env = [];

        if ($tempConfig !== null) {
            $argv[] = '--config='.$tempConfig['path'];
            $env['LARAVEL_CHECKPOINT_TEMP_CONFIG_PATH'] = $tempConfig['path'];
        }

        return new Process(
            $argv,
            env: $env,
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
        $processMax = (int) config('checkpoint.drivers.pgbackrest.process_max', 1);
        $repo = $this->selectedRepositoryId();

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
    private function repositoryOptions(): array
    {
        $repoId = $this->selectedRepositoryId();
        $repository = $this->selectedRepositoryConfig();
        $options = [
            sprintf('--repo%d-type=%s', $repoId, $repository['type']),
        ];

        if ($repository['type'] === 'posix') {
            $options[] = sprintf('--repo%d-path=%s', $repoId, (string) $repository['path']);
        }

        if ($repository['type'] === 's3') {
            $s3 = is_array($repository['s3'] ?? null) ? $repository['s3'] : [];

            $options[] = sprintf('--repo%d-s3-bucket=%s', $repoId, (string) $s3['bucket']);
            $options[] = sprintf('--repo%d-s3-endpoint=%s', $repoId, (string) $s3['endpoint']);
            $options[] = sprintf('--repo%d-s3-region=%s', $repoId, (string) $s3['region']);
            $options[] = sprintf('--repo%d-s3-uri-style=%s', $repoId, (string) ($s3['uri_style'] ?? 'host'));
        }

        $tls = is_array($repository['tls'] ?? null) ? $repository['tls'] : [];
        $options[] = sprintf('--repo%d-storage-verify-tls=%s', $repoId, ($tls['verify'] ?? true) ? 'y' : 'n');

        if (is_string($tls['ca_file'] ?? null) && trim((string) $tls['ca_file']) !== '') {
            $options[] = sprintf('--repo%d-storage-ca-file=%s', $repoId, (string) $tls['ca_file']);
        }

        $encryption = is_array($repository['encryption'] ?? null) ? $repository['encryption'] : [];

        if (($encryption['enabled'] ?? false) === true) {
            $options[] = sprintf('--repo%d-cipher-type=%s', $repoId, (string) $encryption['cipher_type']);
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

    /**
     * @param  array<string, mixed>|null  $summary
     */
    private function normalizeOutput(?array $summary, string $rawOutput): string
    {
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

        $prefix = "[checkpoint-summary]\n".$encodedSummary."\n\n[raw-output]\n";
        $availableBytes = $this->outputCapture()->maxPersistedBytes() - strlen($prefix);

        if ($availableBytes < 1) {
            return "[checkpoint-summary]\n".$encodedSummary;
        }

        return $prefix.$this->outputCapture()->capture($trimmedOutput, $availableBytes)['output'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operationSummary(string $operation, string $output, int $exitCode): ?array
    {
        return match ($operation) {
            'pgbackrest_info' => $this->parseInfoSummary($output),
            'pgbackrest_check', 'pgbackrest_verify' => $this->parseCheckSummary($output, $exitCode),
            default => null,
        };
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

    /**
     * @return array<string, mixed>
     */
    private function plannedMetadata(CommandRun $run): array
    {
        $repoId = $this->selectedRepositoryId();
        $repository = $this->selectedRepositoryConfig();
        $metadata = [
            'stanza' => (string) config('checkpoint.drivers.pgbackrest.stanza', 'main'),
            'repository' => $repoId,
            'metadata' => [
                'driver' => 'pgbackrest',
                'repository_type' => $repository['type'],
            ],
        ];

        return match ($run->operation) {
            'pgbackrest_backup_full' => [...$metadata, 'backup_type' => 'full'],
            'pgbackrest_backup_diff' => [...$metadata, 'backup_type' => 'diff'],
            'pgbackrest_backup_incr' => [...$metadata, 'backup_type' => 'incr'],
            'pgbackrest_restore' => [...$metadata, 'restore_target' => $run->argument_text],
            'pgbackrest_verify', 'pgbackrest_check' => [...$metadata, 'verification_state' => 'pending'],
            'pgbackrest_info' => $metadata,
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    private function completedMetadata(
        CommandRun $run,
        array $plannedMetadata,
        ?array $summary,
        int $exitCode,
        array $captureMetadata,
    ): array {
        $metadata = $plannedMetadata['metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        if ($summary !== null) {
            $metadata['summary'] = $summary;
        }

        $metadata = [
            ...$metadata,
            ...$captureMetadata,
        ];

        $completed = [
            'metadata' => $metadata,
        ];

        if ($summary !== null && $run->operation === 'pgbackrest_info') {
            $latestBackup = $summary['stanzas'][0]['latest_backup'] ?? null;

            if (is_array($latestBackup)) {
                $completed = [
                    ...$completed,
                    'backup_label' => is_string($latestBackup['label'] ?? null) ? $latestBackup['label'] : null,
                    'backup_type' => is_string($latestBackup['type'] ?? null) ? $latestBackup['type'] : null,
                    'backup_size_bytes' => is_int($latestBackup['repository_size'] ?? null) ? $latestBackup['repository_size'] : null,
                    'last_known_good_at' => $this->timestampToCarbon($latestBackup['timestamp_stop'] ?? null),
                ];
            }
        }

        if (in_array($run->operation, ['pgbackrest_check', 'pgbackrest_verify'], true)) {
            $completed['verification_state'] = $exitCode === 0 ? 'verified' : 'failed';
            $completed['verified_at'] = now();

            if ($exitCode === 0) {
                $completed['last_known_good_at'] = now();
            }
        }

        if (in_array($run->operation, ['pgbackrest_backup_full', 'pgbackrest_backup_diff', 'pgbackrest_backup_incr'], true) && $exitCode === 0) {
            $completed['last_known_good_at'] = now();
        }

        return $completed;
    }

    private function timestampToCarbon(mixed $timestamp): ?\Illuminate\Support\Carbon
    {
        if (! is_int($timestamp) || $timestamp < 1) {
            return null;
        }

        return Date::createFromTimestampUTC($timestamp);
    }

    private function logger(): LoggerInterface
    {
        return Log::channel(config('checkpoint.log_channel', 'stack'));
    }

    private function restoreSafetyGuard(): RestoreSafetyGuard
    {
        return resolve(RestoreSafetyGuard::class);
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
            'driver' => 'pgbackrest',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function redactCommandLine(string $commandLine): string
    {
        $patterns = [
            '/(--repo\d+-s3-key=)[^\s]+/',
            '/(--repo\d+-s3-key-secret=)[^\s]+/',
            '/(--repo\d+-cipher-pass=)[^\s]+/',
        ];

        return (string) preg_replace($patterns, '$1[REDACTED]', $commandLine);
    }

    /**
     * @return array{path:string}|null
     */
    private function secretConfigOptions(): ?array
    {
        $repoId = $this->selectedRepositoryId();
        $repository = $this->selectedRepositoryConfig();
        $lines = [];

        if ($repository['type'] === 's3') {
            $s3 = is_array($repository['s3'] ?? null) ? $repository['s3'] : [];

            if (is_string($s3['key'] ?? null) && trim((string) $s3['key']) !== '') {
                $lines[] = sprintf('repo%d-s3-key=%s', $repoId, (string) $s3['key']);
            }

            if (is_string($s3['secret'] ?? null) && trim((string) $s3['secret']) !== '') {
                $lines[] = sprintf('repo%d-s3-key-secret=%s', $repoId, (string) $s3['secret']);
            }
        }

        $encryption = is_array($repository['encryption'] ?? null) ? $repository['encryption'] : [];

        if (($encryption['enabled'] ?? false) === true && is_string($encryption['passphrase'] ?? null) && trim((string) $encryption['passphrase']) !== '') {
            $lines[] = sprintf('repo%d-cipher-pass=%s', $repoId, (string) $encryption['passphrase']);
        }

        if ($lines === []) {
            return null;
        }

        $path = tempnam($this->tempDir(), 'checkpoint-pgbackrest-');

        if ($path === false) {
            throw new ConfigurationException('Unable to allocate a temporary pgBackRest config file.');
        }

        $contents = "[global]\n".implode("\n", $lines)."\n";

        if (file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw new ConfigurationException('Unable to write a temporary pgBackRest config file.');
        }

        @chmod($path, 0600);

        return ['path' => $path];
    }

    private function tempConfigPath(Process $process): ?string
    {
        $path = $process->getEnv()['LARAVEL_CHECKPOINT_TEMP_CONFIG_PATH'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function cleanupTempConfig(?string $path): void
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return;
        }

        @unlink($path);
    }

    private function selectedRepositoryId(): int
    {
        return max(1, (int) config('checkpoint.drivers.pgbackrest.repo', 1));
    }

    private function tempDir(): string
    {
        $configured = trim((string) config('checkpoint.temp_dir', storage_path('app/checkpoint/tmp')));

        if ($configured === '') {
            throw new ConfigurationException('checkpoint.temp_dir must be a non-empty string.');
        }

        if (file_exists($configured) && ! is_dir($configured)) {
            throw new ConfigurationException(
                sprintf('Unable to create checkpoint temp directory [%s].', $configured),
            );
        }

        if (! is_dir($configured) && ! @mkdir($configured, 0700, true) && ! is_dir($configured)) {
            throw new ConfigurationException(
                sprintf('Unable to create checkpoint temp directory [%s].', $configured),
            );
        }

        return $configured;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRepositoryConfig(): array
    {
        $repoId = $this->selectedRepositoryId();
        $repositories = config('checkpoint.drivers.pgbackrest.repositories', []);

        if (! is_array($repositories) || ! is_array($repositories[$repoId] ?? null)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.pgbackrest.repositories must define selected repo [%d].', $repoId),
            );
        }

        return $repositories[$repoId];
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

    private function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }

    private function tapCapturedOutput(CommandRun $run, ?array $outputSession, string $chunk): void
    {
        $this->outputStore()->appendCaptureChunk($outputSession, $chunk);
        $run->recordHeartbeatIfDue();
    }
}
