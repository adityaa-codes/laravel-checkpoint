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
use AdityaaCodes\LaravelCheckpoint\Services\CommandLineRedactor;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

/** @internal */
final class PgDumpDriver implements BackupDriver
{
    public function execute(CommandRun $run): void
    {
        $storedOutputMetadata = null;
        $outputSession = null;

        try {
            if (! $run->claimPendingExecution()) {
                return;
            }

            $run = $run->fresh() ?? $run;
            $plannedMetadata = $this->plannedMetadata($run);
            $restoreAudit = $this->restoreSafetyGuard()->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $process = $this->buildProcess($run, $plannedMetadata);
            $displayCommandLine = $this->redactCommandLine($process->getCommandLine());
            $persistedPlannedMetadata = $this->persistedPlannedMetadata($plannedMetadata);

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata($persistedPlannedMetadata);
            $run = $run->fresh() ?? $run;

            event(new BackupStarted($run));

            $this->logger()->info('Starting pg_dump operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $outputSession = $this->outputStore()->startCapture($run);
            $capturedOutput = $this->outputCapture()->captureProcess(
                $process,
                fn (string $chunk, string $type): null => $this->outputStore()->appendCaptureChunk($outputSession, $chunk),
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

                $this->logger()->info('Completed pg_dump operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $output);
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('pg_dump operation failed', $this->logContext($run, [
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

            $this->logger()->error('pg_dump operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function buildProcess(CommandRun $run, array $plannedMetadata = []): Process
    {
        return match ($run->operation) {
            'logical_backup' => new Process(
                $this->backupCommand($run),
                timeout: $this->commandTimeout(),
            ),
            'logical_restore_file', 'logical_restore_latest' => new Process(
                $this->restoreCommand($run, $plannedMetadata),
                timeout: $this->commandTimeout(),
            ),
            default => throw new ConfigurationException(
                sprintf('Unsupported pg_dump operation [%s].', $run->operation),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function backupCommand(CommandRun $run): array
    {
        $format = $this->format();
        $target = $this->backupTarget($run, $format);
        $command = [
            $this->dumpBinary(),
            '--dbname='.$this->databaseName(),
            '--format='.$this->formatOption($format),
            '--file='.$target,
        ];

        if ($format === 'directory') {
            $command[] = '--jobs='.$this->jobs();
        }

        $command[] = '--compress='.$this->compressLevel();

        return [
            ...$command,
            ...$this->extraArgs('backup'),
        ];
    }

    /**
     * @return list<string>
     */
    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function restoreCommand(CommandRun $run, array $plannedMetadata = []): array
    {
        $format = $this->format();
        $target = $this->restoreTarget($run, $format, $plannedMetadata);
        $command = [
            $this->restoreBinary(),
            '--dbname='.$this->databaseName(),
            '--format='.$this->formatOption($format),
        ];

        if ($format === 'directory') {
            $command[] = '--jobs='.$this->jobs();
        }

        if ((bool) config('checkpoint.drivers.pgdump.clean', true)) {
            $command[] = '--clean';
        }

        if ((bool) config('checkpoint.drivers.pgdump.create', false)) {
            $command[] = '--create';
        }

        return [
            ...$command,
            ...$this->extraArgs('restore'),
            $target,
        ];
    }

    private function dumpBinary(): string
    {
        $binary = (string) config('checkpoint.drivers.pgdump.dump_binary', 'pg_dump');

        if ($binary === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.dump_binary must be a non-empty string.');
        }

        return $binary;
    }

    private function restoreBinary(): string
    {
        $binary = (string) config('checkpoint.drivers.pgdump.restore_binary', 'pg_restore');

        if ($binary === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.restore_binary must be a non-empty string.');
        }

        return $binary;
    }

    private function databaseName(): string
    {
        $database = (string) config('database.connections.'.config('database.default').'.database', '');

        if ($database === '') {
            throw new ConfigurationException('The default database connection must define a database name for pg_dump operations.');
        }

        return $database;
    }

    private function format(): string
    {
        $format = (string) config('checkpoint.drivers.pgdump.format', 'directory');

        if (! in_array($format, ['directory', 'custom'], true)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.pgdump.format [%s] must be directory or custom.', $format),
            );
        }

        return $format;
    }

    private function jobs(): int
    {
        $jobs = (int) config('checkpoint.drivers.pgdump.jobs', 4);

        if ($jobs < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.jobs must be greater than zero.');
        }

        return $jobs;
    }

    private function compressLevel(): int
    {
        $level = (int) config('checkpoint.drivers.pgdump.compress_level', 6);

        if ($level < 0 || $level > 9) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.compress_level must be between 0 and 9.');
        }

        return $level;
    }

    private function outputDir(): string
    {
        $outputDir = (string) config('checkpoint.drivers.pgdump.output_dir', storage_path('app/checkpoint/logical-exports'));

        if ($outputDir === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.output_dir must be a non-empty string.');
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new ConfigurationException(
                sprintf('Unable to create pg_dump output directory [%s].', $outputDir),
            );
        }

        return rtrim($outputDir, '/');
    }

    private function outputPrefix(): string
    {
        $prefix = (string) config('checkpoint.drivers.pgdump.output_prefix', 'logical-export');

        if ($prefix === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.output_prefix must be a non-empty string.');
        }

        return $prefix;
    }

    private function fileExtension(): string
    {
        $extension = trim((string) config('checkpoint.drivers.pgdump.file_extension', 'dump'), '.');

        if ($extension === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.file_extension must be a non-empty string.');
        }

        return $extension;
    }

    private function backupTarget(CommandRun $run, string $format): string
    {
        $basePath = $this->outputDir().'/'.$this->outputPrefix().'-'.$run->getKey();

        return $format === 'directory'
            ? $basePath
            : $basePath.'.'.$this->fileExtension();
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function restoreTarget(CommandRun $run, string $format, array $plannedMetadata = []): string
    {
        if (is_string($plannedMetadata['restore_target'] ?? null) && $plannedMetadata['restore_target'] !== '') {
            $snapshot = is_array($plannedMetadata['restore_target_snapshot'] ?? null)
                ? $plannedMetadata['restore_target_snapshot']
                : null;

            return $this->validatedRestoreTarget((string) $plannedMetadata['restore_target'], $format, $snapshot);
        }

        return match ($run->operation) {
            'logical_restore_latest' => $this->latestBackupTarget($format),
            'logical_restore_file' => $this->restorePathFromArgument($run, $format),
            default => throw new ConfigurationException(
                sprintf('Unsupported pg_dump restore operation [%s].', $run->operation),
            ),
        };
    }

    private function latestBackupTarget(string $format): string
    {
        $trackedTarget = $this->latestTrackedBackupTarget($format);

        if ($trackedTarget !== null) {
            return $trackedTarget;
        }

        $candidates = glob($this->outputDir().'/'.$this->outputPrefix().'-*', GLOB_NOSORT) ?: [];
        $candidates = array_values(array_filter(
            $candidates,
            fn (string $candidate): bool => $format === 'directory' ? is_dir($candidate) : is_file($candidate),
        ));

        if ($candidates === []) {
            throw new ConfigurationException('No logical backup exports were found for logical_restore_latest.');
        }

        usort($candidates, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return $this->validatedRestoreTarget($candidates[0], $format);
    }

    private function latestTrackedBackupTarget(string $format): ?string
    {
        $runs = CommandRun::query()
            ->where('operation', 'logical_backup')
            ->where('status', \AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus::Succeeded)
            ->whereNotNull('artifact_path')
            ->latest('finished_at')
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($runs as $run) {
            if (! $run instanceof CommandRun || ! is_string($run->artifact_path) || trim($run->artifact_path) === '') {
                continue;
            }

            try {
                return $this->validatedRestoreTarget($run->artifact_path, $format);
            } catch (ConfigurationException) {
                continue;
            }
        }

        return null;
    }

    private function restorePathFromArgument(CommandRun $run, string $format): string
    {
        $argument = trim((string) ($run->argument_text ?? ''));

        if ($argument === '') {
            throw new ConfigurationException('logical_restore_file requires a backup path or export name.');
        }

        $resolvedPath = str_starts_with($argument, '/')
            ? $argument
            : $this->outputDir().'/'.ltrim($argument, '/');

        if ($format === 'custom' && pathinfo($resolvedPath, PATHINFO_EXTENSION) === '') {
            $resolvedPath .= '.'.$this->fileExtension();
        }

        return $this->validatedRestoreTarget($resolvedPath, $format);
    }

    /**
     * @param  array<string, mixed>|null  $expectedSnapshot
     */
    private function validatedRestoreTarget(string $resolvedPath, string $format, ?array $expectedSnapshot = null): string
    {
        $realOutputDir = realpath($this->outputDir());
        $realTargetPath = realpath($resolvedPath);

        if (! is_string($realOutputDir) || $realOutputDir === '') {
            throw new ConfigurationException('Unable to resolve the configured pg_dump output directory.');
        }

        if (! is_string($realTargetPath) || $realTargetPath === '') {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $resolvedPath),
            );
        }

        $realOutputDir = rtrim($realOutputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $realTargetPrefix = rtrim($realTargetPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $isContained = str_starts_with($realTargetPath, $realOutputDir)
            || str_starts_with($realTargetPrefix, $realOutputDir);

        if (! $isContained) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be inside the configured pg_dump output directory.', $resolvedPath),
            );
        }

        if ($format === 'directory' && ! is_dir($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be a directory export.', $resolvedPath),
            );
        }

        if ($format === 'custom' && ! is_file($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be a restoreable file export.', $resolvedPath),
            );
        }

        $snapshot = $this->restoreTargetSnapshot($realTargetPath, $format);

        if ($expectedSnapshot !== null && $this->restoreTargetChanged($snapshot, $expectedSnapshot)) {
            throw new ConfigurationException(
                sprintf('logical restore target [%s] changed after validation and must be selected again.', $resolvedPath),
            );
        }

        return $realTargetPath;
    }

    /**
     * @return array{path:string,file_type:string,device:int|null,inode:int|null,mtime:int|null,size:int|null,content_signature:string|null}
     */
    private function restoreTargetSnapshot(string $realTargetPath, string $format): array
    {
        clearstatcache(true, $realTargetPath);

        if (! file_exists($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $realTargetPath),
            );
        }

        $stats = @stat($realTargetPath);

        if ($stats === false) {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $realTargetPath),
            );
        }

        return [
            'path' => $realTargetPath,
            'file_type' => $format,
            'device' => isset($stats['dev']) ? (int) $stats['dev'] : null,
            'inode' => isset($stats['ino']) ? (int) $stats['ino'] : null,
            'mtime' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            'size' => $format === 'custom' && isset($stats['size']) ? (int) $stats['size'] : null,
            'content_signature' => $format === 'directory' ? $this->directoryContentSignature($realTargetPath) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $currentSnapshot
     * @param  array<string, mixed>  $expectedSnapshot
     */
    private function restoreTargetChanged(array $currentSnapshot, array $expectedSnapshot): bool
    {
        foreach (['path', 'file_type', 'device', 'inode', 'mtime', 'size', 'content_signature'] as $key) {
            if (($currentSnapshot[$key] ?? null) !== ($expectedSnapshot[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function directoryContentSignature(string $path): string
    {
        $entries = [];
        $baseLength = strlen(rtrim($path, DIRECTORY_SEPARATOR)) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $relativePath = substr($file->getPathname(), $baseLength);
            $entries[] = implode('|', [
                $relativePath,
                $file->isDir() ? 'dir' : 'file',
                $file->getInode(),
                $file->getSize(),
                $file->getMTime(),
            ]);
        }

        sort($entries);

        return sha1(implode("\n", $entries));
    }

    private function formatOption(string $format): string
    {
        return match ($format) {
            'directory' => 'directory',
            'custom' => 'custom',
            default => throw new ConfigurationException(
                sprintf('Unsupported pg_dump format [%s].', $format),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function extraArgs(string $key): array
    {
        $value = config("checkpoint.drivers.pgdump.extra_args.{$key}", []);

        if (! is_array($value)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.pgdump.extra_args.%s must be an array.', $key),
            );
        }

        $args = array_values(array_filter(
            $value,
            static fn (mixed $arg): bool => is_string($arg) && $arg !== '',
        ));

        /** @var list<string> $args */
        return $args;
    }

    private function commandTimeout(): float
    {
        $timeout = (int) config('checkpoint.drivers.pgdump.command_timeout_seconds', 7200);

        if ($timeout < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.command_timeout_seconds must be greater than zero.');
        }

        return (float) $timeout;
    }

    /**
     * @return array<string, mixed>
     */
    private function plannedMetadata(CommandRun $run): array
    {
        return match ($run->operation) {
            'logical_backup' => [
                'backup_type' => 'logical_export',
                'artifact_path' => $this->backupTarget($run, $this->format()),
                'verification_state' => 'not_applicable',
                'metadata' => [
                    'driver' => 'pgdump',
                    'format' => $this->format(),
                    'jobs' => $this->jobs(),
                    'compress_level' => $this->compressLevel(),
                ],
            ],
            'logical_restore_latest', 'logical_restore_file' => [
                ...$this->resolvedRestoreTargetMetadata($run, $this->format()),
                'metadata' => [
                    'driver' => 'pgdump',
                    'format' => $this->format(),
                    'jobs' => $this->jobs(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array{restore_target:string,restore_target_snapshot:array<string,mixed>}
     */
    private function resolvedRestoreTargetMetadata(CommandRun $run, string $format): array
    {
        $target = $this->restoreTarget($run, $format);

        return [
            'restore_target' => $target,
            'restore_target_snapshot' => $this->restoreTargetSnapshot($target, $format),
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function persistedPlannedMetadata(array $plannedMetadata): array
    {
        unset($plannedMetadata['restore_target_snapshot']);

        return $plannedMetadata;
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = $plannedMetadata['metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $completed = [
            'metadata' => [
                ...$metadata,
                ...$captureMetadata,
            ],
        ];

        if ($run->operation === 'logical_backup') {
            $artifactPath = $plannedMetadata['artifact_path'] ?? null;
            $backupSize = is_string($artifactPath) ? $this->pathSize($artifactPath) : null;

            $completed['backup_size_bytes'] = $backupSize;

            if ($exitCode === 0) {
                if (is_string($artifactPath)) {
                    $completed['metadata']['artifact_snapshot'] = $this->artifactSnapshot($artifactPath, (string) ($metadata['format'] ?? $this->format()));
                }

                $completed['last_known_good_at'] = now();
            }
        }

        if (in_array($run->operation, ['logical_restore_latest', 'logical_restore_file'], true)) {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'restored_via' => 'pg_restore',
            ];
        }

        return $completed;
    }

    private function pathSize(string $path): ?int
    {
        if (is_file($path)) {
            $size = filesize($path);

            return $size === false ? null : $size;
        }

        if (! is_dir($path)) {
            return null;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $size += $file->getSize();
        }

        return $size;
    }

    private function logger(): LoggerInterface
    {
        return Log::channel(config('checkpoint.log_channel', 'stack'));
    }

    private function redactCommandLine(string $commandLine): string
    {
        return $this->commandLineRedactor()->redact($commandLine);
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
            'driver' => 'pgdump',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
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

    /**
     * @return array<string, mixed>|null
     */
    private function artifactSnapshot(string $path, string $format): ?array
    {
        try {
            return $this->restoreTargetSnapshot($path, $format);
        } catch (ConfigurationException) {
            return null;
        }
    }

    private function commandLineRedactor(): CommandLineRedactor
    {
        return resolve(CommandLineRedactor::class);
    }
}
