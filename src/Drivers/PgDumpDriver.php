<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\Concerns\ExecutesReplicationSync;
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
final class PgDumpDriver implements BackupDriver
{
    use ExecutesReplicationSync;

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
            $displayCommandLine = $this->redactCommandLine($this->displayCommandLine($run, $plannedMetadata));
            $plannedMetadata = $this->redactedReplicationMetadata($plannedMetadata);
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

            if ($run->operation === 'replication_sync') {
                $result = $this->executeReplicationSync($run, $plannedMetadata);
                $storedOutput = $this->outputStore()->persist($run, $result['output']);
                $storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
                $output = $storedOutput['command_output'] ?? '';
                $exitCode = (int) $result['exit_code'];
                $completedMetadata = $this->completedMetadata(
                    $run,
                    $plannedMetadata,
                    $exitCode,
                    [
                        ...$result['metadata'],
                        ...$storedOutput['metadata'],
                    ],
                );
            } else {
                $process = $this->buildProcess($run, $plannedMetadata);
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
            }

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
            'replication_sync' => new Process(
                $this->replicationDryRunCommand($plannedMetadata),
                timeout: $this->commandTimeout(),
            ),
            'backup_drill' => new Process(
                $this->drillCommand($run, $plannedMetadata),
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
        if ($run->operation === 'replication_sync') {
            throw new ConfigurationException('replication_sync must be executed through replication dry-run command flow.');
        }

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
        if ($run->operation === 'replication_sync') {
            throw new ConfigurationException('replication_sync must be executed through replication apply command flow.');
        }

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

            return $this->validatedRestoreTarget($plannedMetadata['restore_target'], $format, $snapshot);
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
            ->where('status', CommandRunStatus::Succeeded)
            ->whereNotNull('artifact_path')
            ->latest('finished_at')
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($runs as $run) {
            if (! is_string($run->artifact_path)) {
                continue;
            }
            if (trim((string) $run->artifact_path) === '') {
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

        if ($realOutputDir === false) {
            throw new ConfigurationException('Unable to resolve the configured pg_dump output directory.');
        }

        if ($realTargetPath === false) {
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
            'device' => (int) $stats['dev'],
            'inode' => (int) $stats['ino'],
            'mtime' => (int) $stats['mtime'],
            'size' => $format === 'custom' ? (int) $stats['size'] : null,
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
            $relativePath = substr((string) $file->getPathname(), $baseLength);
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
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function drillCommand(CommandRun $run, array $plannedMetadata): array
    {
        $template = trim((string) config('checkpoint.drivers.pgdump.drill_command', ''));

        if ($template !== '') {
            $argv = preg_split('/\s+/', $template);

            if ($argv === false) {
                throw new ConfigurationException('checkpoint.drivers.pgdump.drill_command must contain a valid executable token.');
            }

            $replacements = [
                '{db}' => $this->databaseName(),
                '{target}' => $plannedMetadata['drill_artifact_path'] ?? '',
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

        $target = $plannedMetadata['drill_artifact_path'] ?? $this->latestBackupTarget($this->format());

        return [
            $this->restoreBinary(),
            '--list',
            $target,
        ];
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
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        return match ($run->operation) {
            'backup_drill' => trim((string) config('checkpoint.drivers.pgdump.drill_command', '')) !== ''
                ? $this->buildProcess($run, $plannedMetadata)->getCommandLine()
                : sprintf('pg_restore -l %s', $plannedMetadata['drill_artifact_path'] ?? ''),
            'replication_sync' => implode(' ; ', array_filter([
                (new Process($this->replicationDryRunCommand($plannedMetadata), timeout: $this->commandTimeout()))->getCommandLine(),
                (bool) ($plannedMetadata['metadata']['replication']['apply_requested'] ?? false)
                    ? (new Process($this->replicationApplyCommand((string) (($plannedMetadata['metadata']['replication']['artifact_path'] ?? ''))), timeout: $this->commandTimeout()))->getCommandLine()
                    : null,
            ])),
            default => $this->buildProcess($run, $plannedMetadata)->getCommandLine(),
        };
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
            'backup_drill' => [
                'drill_artifact_path' => $this->latestBackupTarget($this->format()),
                'metadata' => [
                    'driver' => 'pgdump',
                ],
            ],
            'replication_sync' => [
                'metadata' => [
                    'driver' => 'pgdump',
                    'replication' => $this->replicationPlan($run),
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
     * @param  array<string, mixed>  $captureMetadata
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

        if ($run->operation === 'replication_sync') {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'replicated_via' => 'pg_dump+pg_restore',
            ];
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

    private function postRestoreVerificationBuilder(): PostRestoreVerificationBuilder
    {
        return resolve(PostRestoreVerificationBuilder::class);
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
            'restore_decision_event_count' => $this->restoreDecisionEventCount($run),
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
     * @param  array{disk:string,path:string,temp_path:string}|null  $outputSession
     */
    private function tapCapturedOutput(CommandRun $run, ?array $outputSession, string $chunk): void
    {
        $this->outputStore()->appendCaptureChunk($outputSession, $chunk);
        $run->recordHeartbeatIfDue();
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

    private function restoreDecisionEventCount(CommandRun $run): ?int
    {
        if (! in_array($run->operation, ['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore', 'replication_sync'], true)) {
            return null;
        }

        if (! $run->exists) {
            return null;
        }

        return RestoreDecisionEvent::query()
            ->where('command_run_id', (int) $run->getKey())
            ->count();
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function redactedReplicationMetadata(array $plannedMetadata): array
    {
        if (! is_array($plannedMetadata['metadata']['replication'] ?? null)) {
            return $plannedMetadata;
        }

        $replication = $plannedMetadata['metadata']['replication'];

        foreach (['source', 'destination'] as $role) {
            if (is_array($replication[$role] ?? null) && is_string($replication[$role]['redacted'] ?? null)) {
                $replication[$role]['redacted'] = $this->redactCommandLine($replication[$role]['redacted']);
            }
        }

        $plannedMetadata['metadata']['replication'] = $replication;

        return $plannedMetadata;
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeDryRunReplication(array $plannedMetadata, CommandRun $run): array
    {
        $process = new Process(
            $this->replicationDryRunCommand($plannedMetadata),
            timeout: $this->commandTimeout(),
        );

        $captured = $this->outputCapture()->captureProcess(
            $process,
            fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, null, $chunk),
        );

        return [
            'output' => $captured['output'],
            'exit_code' => $process->getExitCode() ?? -1,
            'metadata' => $captured['metadata'],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeApplyReplication(string $artifactPath, CommandRun $run): array
    {
        $process = new Process(
            $this->replicationApplyCommand($artifactPath),
            timeout: $this->commandTimeout(),
        );

        $captured = $this->outputCapture()->captureProcess(
            $process,
            fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, null, $chunk),
        );

        return [
            'output' => $captured['output'],
            'exit_code' => $process->getExitCode() ?? -1,
            'metadata' => $captured['metadata'],
        ];
    }

    private function replicationDriverLabel(): string
    {
        return 'pg_dump';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return list<string>
     */
    private function replicationDryRunCommand(array $plannedMetadata): array
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('pg_dump replication requires a writable staging artifact path.');
        }

        return [
            $this->dumpBinary(),
            '--dbname='.$this->databaseName(),
            '--format=custom',
            '--file='.$artifactPath,
            ...$this->extraArgs('backup'),
        ];
    }

    /**
     * @return list<string>
     */
    private function replicationApplyCommand(string $artifactPath): array
    {
        return [
            $this->restoreBinary(),
            '--dbname='.$this->databaseName(),
            '--format=custom',
            ...$this->extraArgs('restore'),
            $artifactPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeReplicationSync(CommandRun $run, array $plannedMetadata): array
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $this->assertLocalConfiguredReplicationSemantics($replication);
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('pg_dump replication requires a writable staging artifact path.');
        }

        $applyRequested = (bool) ($replication['apply_requested'] ?? false);
        $dryRunRequested = (bool) ($replication['dry_run_requested'] ?? true);
        $this->assertReplicationGovernanceAllowsApply($replication, $applyRequested, $dryRunRequested);

        $dryRunProcess = new Process(
            $this->replicationDryRunCommand($plannedMetadata),
            timeout: $this->commandTimeout(),
        );

        $dryRun = $this->outputCapture()->captureProcess(
            $dryRunProcess,
            fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, null, $chunk),
        );
        $dryRunExitCode = $dryRunProcess->getExitCode() ?? -1;

        if ($dryRunExitCode !== 0) {
            is_file($artifactPath) && unlink($artifactPath);
            $analysis = $this->failureAnalysis(
                stage: 'dry_run_export',
                failureOutput: $dryRun['output'],
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output'].$this->renderDebugSuggestions($analysis),
                'exit_code' => $dryRunExitCode,
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'dry_run_export',
                            'reason' => 'pg_dump dry-run export command failed.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        $sourceSnapshot = $this->replicationArtifactSnapshot($artifactPath);
        $overwriteAllowed = (bool) ($replication['overwrite_destination'] ?? false) || (bool) ($replication['force_requested'] ?? false);

        if (! $applyRequested || $dryRunRequested) {
            is_file($artifactPath) && unlink($artifactPath);

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output'],
                'exit_code' => 0,
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'dry_run_only',
                        'sanity' => [
                            'source_snapshot' => $sourceSnapshot,
                            'method' => 'artifact_hash',
                            'destination_check' => 'skipped',
                            'fallback_reason' => 'apply_not_requested_or_dry_run_enforced',
                        ],
                    ],
                ],
            ];
        }

        if (! $overwriteAllowed) {
            is_file($artifactPath) && unlink($artifactPath);
            $analysis = $this->failureAnalysis(
                stage: 'apply_gate',
                failureOutput: 'Destination overwrite denied by policy.',
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                    'overwrite_destination' => (bool) ($replication['overwrite_destination'] ?? false),
                    'force_requested' => (bool) ($replication['force_requested'] ?? false),
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                    ."\n[replication_sync:apply_gate]\nDestination overwrite denied by policy."
                    .$this->renderDebugSuggestions($analysis),
                'exit_code' => 2,
                'metadata' => [
                    ...$dryRun['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'apply_gate',
                            'reason' => 'Destination overwrite is blocked by default.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        $applyProcess = new Process(
            $this->replicationApplyCommand($artifactPath),
            timeout: $this->commandTimeout(),
        );
        $apply = $this->outputCapture()->captureProcess(
            $applyProcess,
            fn (string $chunk, string $type): null => $this->tapCapturedOutput($run, null, $chunk),
        );
        $applyExitCode = $applyProcess->getExitCode() ?? -1;
        is_file($artifactPath) && unlink($artifactPath);

        $sanity = [
            'source_snapshot' => $sourceSnapshot,
            'method' => 'artifact_hash',
            'destination_check' => $applyExitCode === 0 ? 'apply_exit_code_zero' : 'apply_failed',
            'fallback_reason' => 'live_destination_checksum_not_available_in_v1',
        ];

        if ($applyExitCode !== 0) {
            $analysis = $this->failureAnalysis(
                stage: 'apply_restore',
                failureOutput: $apply['output'],
                context: [
                    'engine' => $replication['engine'] ?? null,
                    'source' => (is_array($replication['source'] ?? null) ? ($replication['source']['redacted'] ?? null) : null),
                    'destination' => (is_array($replication['destination'] ?? null) ? ($replication['destination']['redacted'] ?? null) : null),
                    'sanity_method' => $sanity['method'],
                ],
            );

            return [
                'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                    ."\n[replication_sync:apply]\n".$apply['output']
                    .$this->renderDebugSuggestions($analysis),
                'exit_code' => $applyExitCode,
                'metadata' => [
                    ...$dryRun['metadata'],
                    ...$apply['metadata'],
                    'replication' => [
                        ...$replication,
                        'result' => 'failed',
                        'sanity' => $sanity,
                        'failure_analysis' => $analysis,
                        'failure_context' => [
                            'stage' => 'apply_restore',
                            'reason' => 'pg_restore apply phase failed on destination.',
                            'suggestions' => $this->legacySuggestions($analysis),
                        ],
                    ],
                ],
            ];
        }

        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                ."\n[replication_sync:apply]\n".$apply['output'],
            'exit_code' => 0,
            'metadata' => [
                ...$dryRun['metadata'],
                ...$apply['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'applied',
                    'sanity' => $sanity,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function replicationPlan(CommandRun $run): array
    {
        $payload = $this->replicationPayload($run);
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $replicationMetadata = is_array($metadata['replication'] ?? null) ? $metadata['replication'] : [];
        $engine = strtolower(trim((string) ($replicationMetadata['engine'] ?? '')));

        if ($engine === '') {
            throw new ConfigurationException('replication_sync requires replication.engine metadata.');
        }

        if ($engine !== 'pgsql') {
            throw new ConfigurationException(sprintf(
                'Unsupported replication engine [%s] for pg_dump driver. pg_dump driver supports pgsql -> pgsql only.',
                $engine,
            ));
        }

        return [
            'engine' => 'pgsql',
            'source' => is_array($replicationMetadata['source'] ?? null) ? $replicationMetadata['source'] : null,
            'destination' => is_array($replicationMetadata['destination'] ?? null) ? $replicationMetadata['destination'] : null,
            'dry_run_requested' => (bool) ($payload['dry_run'] ?? true),
            'apply_requested' => (bool) ($payload['apply'] ?? false),
            'force_requested' => (bool) ($payload['force'] ?? false),
            'overwrite_destination' => (bool) ($payload['overwrite_destination'] ?? false),
            'governance_preflight' => is_array($payload['governance_preflight'] ?? null)
                ? $payload['governance_preflight']
                : (is_array($replicationMetadata['governance_preflight'] ?? null) ? $replicationMetadata['governance_preflight'] : null),
            'artifact_path' => $this->replicationArtifactPath($run),
        ];
    }

    private function replicationArtifactPath(CommandRun $run): string
    {
        return sprintf(
            '%s/%s-%d.dump',
            rtrim($this->outputDir(), '/'),
            'checkpoint-replication',
            (int) $run->getKey(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replicationArtifactSnapshot(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return [
            'path' => $path,
            'size' => $size === false ? null : $size,
            'sha1' => is_file($path) ? sha1_file($path) : null,
        ];
    }
}
