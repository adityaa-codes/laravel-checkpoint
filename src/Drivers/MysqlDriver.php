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
final class MysqlDriver implements BackupDriver
{
    use ExecutesReplicationSync;

    private ?CommandRun $activeRun = null;

    public function execute(CommandRun $run): void
    {
        $storedOutputMetadata = null;

        try {
            if (! $run->claimPendingExecution()) {
                return;
            }

            $run = $run->fresh() ?? $run;
            $this->activeRun = $run;
            $plannedMetadata = $this->plannedMetadata($run);
            $plannedMetadata = $this->redactedReplicationMetadata($plannedMetadata);
            $restoreAudit = $this->restoreSafetyGuard()->ensureSafe($run, $plannedMetadata);
            $plannedMetadata = $this->mergeRestoreAuditMetadata($plannedMetadata, $restoreAudit);
            $displayCommandLine = $this->redactCommandLine($this->displayCommandLine($run, $plannedMetadata));
            $persistedPlannedMetadata = $this->persistedPlannedMetadata($plannedMetadata);

            $run->forceFill([
                'command_line' => $displayCommandLine,
            ])->save();
            $run->recordMetadata($persistedPlannedMetadata);
            $run = $run->fresh() ?? $run;

            event(new BackupStarted($run));

            $this->logger()->info('Starting mysql operation', $this->logContext($run, [
                'command_line' => $displayCommandLine,
            ]));

            $result = $this->executeOperation($run, $plannedMetadata);
            $storedOutput = $this->outputStore()->persist($run, $result['output']);
            $storedOutputMetadata = $storedOutput['metadata']['output_storage'] ?? null;
            $output = (string) ($storedOutput['command_output'] ?? '');
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

                $this->logger()->info('Completed mysql operation', $this->logContext($run, [
                    'exit_code' => $exitCode,
                ]));

                return;
            }

            $run->markAsFailed($exitCode, $output);
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, $exitCode, $output));

            $this->logger()->error('mysql operation failed', $this->logContext($run, [
                'exit_code' => $exitCode,
            ]));
        } catch (Throwable $exception) {
            if (is_array($storedOutputMetadata)) {
                $this->outputStore()->cleanupMetadata($storedOutputMetadata);
            }

            $run->markAsFailed(output: $exception->getMessage());
            $run = $run->fresh() ?? $run;
            event(new BackupFailed($run, -1, $exception->getMessage(), $exception));

            $this->logger()->error('mysql operation crashed', $this->logContext($run, [
                'error' => $exception->getMessage(),
            ]));

            throw $exception;
        } finally {
            $this->activeRun = null;
        }
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeOperation(CommandRun $run, array $plannedMetadata): array
    {
        return match ($run->operation) {
            'logical_backup' => $this->executeSingleProcess($this->buildProcess($run, $plannedMetadata)),
            'logical_restore_file', 'logical_restore_latest' => $this->executeLogicalRestore($run, $plannedMetadata),
            'pitr_restore' => $this->executePitrRestore($run, $plannedMetadata),
            'replication_sync' => $this->executeReplicationSync($run, $plannedMetadata),
            'backup_drill' => $this->executeDrillOperation($run, $plannedMetadata),
            default => throw new ConfigurationException(
                sprintf('Unsupported mysql operation [%s].', $run->operation),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeDrillOperation(CommandRun $run, array $plannedMetadata): array
    {
        $drillCommand = trim((string) config('checkpoint.drivers.mysql.drill_command', ''));

        if ($drillCommand !== '') {
            return $this->executeSingleProcess($this->buildProcess($run, $plannedMetadata));
        }

        return $this->executeInlineDrillValidation();
    }

    /**
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeInlineDrillValidation(): array
    {
        try {
            $target = $this->latestBackupTarget();
            $size = filesize($target);

            if ($size === false || $size === 0) {
                return [
                    'output' => 'Default drill: latest backup artifact is empty or unreadable.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'artifact_empty',
                        ],
                    ],
                ];
            }

            $handle = fopen($target, 'r');

            if ($handle === false) {
                return [
                    'output' => 'Default drill: cannot open latest backup artifact.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'unreadable',
                        ],
                    ],
                ];
            }

            $head = fread($handle, 5242880);
            fclose($handle);

            if ($head === false || $head === '') {
                return [
                    'output' => 'Default drill: latest backup artifact is empty.',
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'head_empty',
                        ],
                    ],
                ];
            }

            $createTableCount = substr_count(strtoupper($head), 'CREATE TABLE');
            $insertCount = substr_count(strtoupper($head), 'INSERT INTO');

            if ($createTableCount === 0 && $insertCount === 0) {
                return [
                    'output' => sprintf(
                        'Default drill: artifact [%s] (%d bytes) does not contain recognizable SQL structure markers.',
                        basename($target),
                        $size,
                    ),
                    'exit_code' => 1,
                    'metadata' => [
                        'drill' => [
                            'method' => 'inline_structure_check',
                            'artifact_path' => $target,
                            'result' => 'no_structure_markers',
                            'artifact_size_bytes' => $size,
                        ],
                    ],
                ];
            }

            return [
                'output' => sprintf(
                    'Default drill: artifact [%s] (%d bytes) contains CREATE TABLE=%d INSERT INTO=%d — structure valid.',
                    basename($target),
                    $size,
                    $createTableCount,
                    $insertCount,
                ),
                'exit_code' => 0,
                'metadata' => [
                    'drill' => [
                        'method' => 'inline_structure_check',
                        'artifact_path' => $target,
                        'result' => 'structure_valid',
                        'artifact_size_bytes' => $size,
                        'create_table_count' => $createTableCount,
                        'insert_into_count' => $insertCount,
                    ],
                ],
            ];
        } catch (ConfigurationException $e) {
            return [
                'output' => sprintf('Default drill: cannot resolve latest backup artifact — %s', $e->getMessage()),
                'exit_code' => 1,
                'metadata' => [
                    'drill' => [
                        'method' => 'inline_structure_check',
                        'result' => 'no_artifact_found',
                    ],
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeLogicalRestore(CommandRun $run, array $plannedMetadata): array
    {
        $restoreTarget = (string) ($plannedMetadata['restore_target'] ?? '');

        if ($restoreTarget === '') {
            throw new ConfigurationException('Unable to resolve mysql restore target.');
        }

        $contents = is_file($restoreTarget) ? file_get_contents($restoreTarget) : false;

        if (! is_string($contents)) {
            throw new ConfigurationException(sprintf('Unable to read mysql restore target [%s].', $restoreTarget));
        }

        return $this->executeSingleProcess(
            $this->buildProcess($run, $plannedMetadata),
            $contents,
        );
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executePitrRestore(CommandRun $run, array $plannedMetadata): array
    {
        $baseTarget = (string) ($plannedMetadata['pitr_base_target'] ?? '');
        $targetTime = trim((string) ($plannedMetadata['restore_target'] ?? $run->argument_text ?? ''));

        if ($baseTarget === '') {
            throw new ConfigurationException('pitr_restore requires a baseline logical backup artifact.');
        }

        if ($targetTime === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        $baseContents = is_file($baseTarget) ? file_get_contents($baseTarget) : false;

        if (! is_string($baseContents)) {
            throw new ConfigurationException(sprintf('Unable to read mysql PITR baseline [%s].', $baseTarget));
        }

        $restoreBaseline = $this->executeSingleProcess(
            $this->mysqlRestoreProcess(),
            $baseContents,
        );

        if ($restoreBaseline['exit_code'] !== 0) {
            return [
                'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output'],
                'exit_code' => $restoreBaseline['exit_code'],
                'metadata' => [
                    ...$restoreBaseline['metadata'],
                    'pitr' => [
                        'failed_step' => 'baseline_restore',
                        'target_time' => $targetTime,
                    ],
                ],
            ];
        }

        $binlogOutputPath = tempnam($this->tempDir(), 'checkpoint-mysql-pitr-');

        if ($binlogOutputPath === false) {
            throw new ConfigurationException('Unable to allocate temporary mysql PITR binlog output path.');
        }

        $binlogReplay = $this->executeSingleProcess(
            $this->mysqlBinlogProcess($targetTime, $binlogOutputPath),
        );

        if ($binlogReplay['exit_code'] !== 0) {
            is_file($binlogOutputPath) && File::delete($binlogOutputPath);

            return [
                'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output']
                    ."\n[pitr_restore:binlog]\n".$binlogReplay['output'],
                'exit_code' => $binlogReplay['exit_code'],
                'metadata' => [
                    ...$restoreBaseline['metadata'],
                    ...$binlogReplay['metadata'],
                    'pitr' => [
                        'failed_step' => 'binlog_extract',
                        'target_time' => $targetTime,
                        'baseline_artifact_path' => $baseTarget,
                        'binlog_files' => $this->pitrBinlogFiles(),
                    ],
                ],
            ];
        }

        $binlogSql = is_file($binlogOutputPath) ? file_get_contents($binlogOutputPath) : false;
        is_file($binlogOutputPath) && File::delete($binlogOutputPath);

        if (! is_string($binlogSql)) {
            throw new ConfigurationException('Unable to read extracted mysql PITR binlog SQL.');
        }

        $applyReplay = $this->executeSingleProcess(
            $this->mysqlPitrReplayProcess(),
            $binlogSql,
        );

        return [
            'output' => "[pitr_restore:baseline]\n".$restoreBaseline['output']
                ."\n[pitr_restore:binlog]\n".$binlogReplay['output']
                ."\n[pitr_restore:apply]\n".$applyReplay['output'],
            'exit_code' => $applyReplay['exit_code'],
            'metadata' => [
                ...$restoreBaseline['metadata'],
                ...$binlogReplay['metadata'],
                ...$applyReplay['metadata'],
                'pitr' => [
                    'failed_step' => $applyReplay['exit_code'] === 0 ? null : 'binlog_apply',
                    'target_time' => $targetTime,
                    'baseline_artifact_path' => $baseTarget,
                    'binlog_files' => $this->pitrBinlogFiles(),
                ],
            ],
        ];
    }

    /**
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeSingleProcess(Process $process, ?string $input = null): array
    {
        if ($input !== null) {
            $process->setInput($input);
        }

        $captured = $this->outputCapture()->captureProcess(
            $process,
            fn (string $chunk, string $type): null => $this->tapCapturedOutput(),
        );

        return [
            'output' => $captured['output'],
            'exit_code' => $process->getExitCode() ?? -1,
            'metadata' => $captured['metadata'],
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function buildProcess(CommandRun $run, array $plannedMetadata = []): Process
    {
        return match ($run->operation) {
            'logical_backup' => $this->mysqlBackupProcess($run),
            'logical_restore_file', 'logical_restore_latest' => $this->mysqlRestoreProcessAfterValidatingTarget($run, $plannedMetadata),
            'pitr_restore' => $this->mysqlBinlogProcess((string) ($plannedMetadata['restore_target'] ?? $run->argument_text ?? '')),
            'replication_sync' => $this->mysqlReplicationDryRunProcess($plannedMetadata),
            'backup_drill' => $this->mysqlDrillProcess($run),
            default => throw new ConfigurationException(
                sprintf('Unsupported mysql operation [%s].', $run->operation),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function mysqlRestoreProcessAfterValidatingTarget(CommandRun $run, array $plannedMetadata): Process
    {
        $plannedTarget = $plannedMetadata['restore_target'] ?? null;

        if (is_string($plannedTarget) && trim($plannedTarget) !== '') {
            $snapshot = is_array($plannedMetadata['restore_target_snapshot'] ?? null)
                ? $plannedMetadata['restore_target_snapshot']
                : null;

            $this->validatedRestoreTarget($plannedTarget, $run->operation, $snapshot);
        } else {
            match ($run->operation) {
                'logical_restore_file' => $this->restorePathFromArgument($run),
                'logical_restore_latest' => $this->latestBackupTarget(),
                default => throw new ConfigurationException(
                    sprintf('Unsupported mysql restore operation [%s].', $run->operation),
                ),
            };
        }

        return $this->mysqlRestoreProcess();
    }

    private function mysqlBackupProcess(CommandRun $run): Process
    {
        $command = [
            $this->mysqldumpBinary(),
            '--databases',
            $this->databaseName(),
            '--result-file='.$this->backupTarget($run),
        ];

        if ((bool) config('checkpoint.drivers.mysql.single_transaction', true)) {
            $command[] = '--single-transaction';
        }

        if ((bool) config('checkpoint.drivers.mysql.quick', true)) {
            $command[] = '--quick';
        }

        if ((bool) config('checkpoint.drivers.mysql.skip_lock_tables', true)) {
            $command[] = '--skip-lock-tables';
        }

        return new Process(
            [...$command, ...$this->extraArgs('backup')],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqlRestoreProcess(): Process
    {
        return new Process(
            [
                $this->mysqlBinary(),
                '--database='.$this->databaseName(),
                ...$this->extraArgs('restore'),
            ],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqlBinlogProcess(string $targetTime, ?string $resultFile = null): Process
    {
        $trimmedTarget = trim($targetTime);

        if ($trimmedTarget === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        $files = $this->pitrBinlogFiles();

        if ($files === []) {
            throw new ConfigurationException(
                'checkpoint.drivers.mysql.pitr.binlog_files must list at least one binary log for pitr_restore.',
            );
        }

        $command = [
            $this->mysqlbinlogBinary(),
            '--stop-datetime='.$trimmedTarget,
            ...$this->extraArgs('pitr_binlog'),
        ];

        if (is_string($resultFile) && trim($resultFile) !== '') {
            $command[] = '--result-file='.$resultFile;
        }

        return new Process(
            [...$command, ...$files],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqlPitrReplayProcess(): Process
    {
        return new Process(
            [
                $this->mysqlBinary(),
                '--binary-mode',
                '--database='.$this->databaseName(),
                ...$this->extraArgs('pitr_replay'),
            ],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqlDrillProcess(CommandRun $run): Process
    {
        $template = trim((string) config('checkpoint.drivers.mysql.drill_command', ''));

        if ($template === '') {
            throw new ConfigurationException(
                'checkpoint.drivers.mysql.drill_command must be configured for backup_drill when checkpoint.driver is mysql.',
            );
        }

        $argv = preg_split('/\s+/', $template);

        if ($argv === false) {
            throw new ConfigurationException('checkpoint.drivers.mysql.drill_command must contain a valid executable token.');
        }

        $replacements = [
            '{db}' => $this->databaseName(),
            '{target}' => trim((string) ($run->argument_text ?? '')),
            '{backup_dir}' => $this->outputDir(),
        ];

        $command = array_map(
            static fn (string $token): string => str_replace(
                array_keys($replacements),
                array_values($replacements),
                $token,
            ),
            $argv,
        );

        return new Process(
            [...$command, ...$this->extraArgs('drill')],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqldumpBinary(): string
    {
        $binary = (string) config('checkpoint.drivers.mysql.dump_binary', 'mysqldump');

        if (trim($binary) === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.dump_binary must be a non-empty string.');
        }

        return $binary;
    }

    private function mysqlBinary(): string
    {
        $binary = (string) config('checkpoint.drivers.mysql.mysql_binary', 'mysql');

        if (trim($binary) === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.mysql_binary must be a non-empty string.');
        }

        return $binary;
    }

    private function mysqlbinlogBinary(): string
    {
        $binary = (string) config('checkpoint.drivers.mysql.mysqlbinlog_binary', 'mysqlbinlog');

        if (trim($binary) === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.mysqlbinlog_binary must be a non-empty string.');
        }

        return $binary;
    }

    private function databaseName(): string
    {
        $database = (string) config('database.connections.'.config('database.default').'.database', '');

        if ($database === '') {
            throw new ConfigurationException('The default database connection must define a database name for mysql operations.');
        }

        return $database;
    }

    private function outputDir(): string
    {
        $outputDir = (string) config('checkpoint.drivers.mysql.output_dir', storage_path('app/checkpoint/mysql/logical-exports'));

        if (trim($outputDir) === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.output_dir must be a non-empty string.');
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw new ConfigurationException(
                sprintf('Unable to create mysql output directory [%s].', $outputDir),
            );
        }

        return rtrim($outputDir, '/');
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

        if (! is_dir($configured) && ! File::makeDirectory($configured, 0700, true, true) && ! is_dir($configured)) {
            throw new ConfigurationException(
                sprintf('Unable to create checkpoint temp directory [%s].', $configured),
            );
        }

        return $configured;
    }

    private function outputPrefix(): string
    {
        $prefix = (string) config('checkpoint.drivers.mysql.output_prefix', 'mysql-export');

        if (trim($prefix) === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.output_prefix must be a non-empty string.');
        }

        return $prefix;
    }

    private function fileExtension(): string
    {
        $extension = trim((string) config('checkpoint.drivers.mysql.file_extension', 'sql'), '.');

        if ($extension === '') {
            throw new ConfigurationException('checkpoint.drivers.mysql.file_extension must be a non-empty string.');
        }

        return $extension;
    }

    private function backupTarget(CommandRun $run): string
    {
        return sprintf(
            '%s/%s-%d.%s',
            $this->outputDir(),
            $this->outputPrefix(),
            (int) $run->getKey(),
            $this->fileExtension(),
        );
    }

    private function restorePathFromArgument(CommandRun $run): string
    {
        $argument = trim((string) ($run->argument_text ?? ''));

        if ($argument === '') {
            throw new ConfigurationException('logical_restore_file requires a backup path or export name.');
        }

        $resolvedPath = str_starts_with($argument, '/')
            ? $argument
            : $this->outputDir().'/'.ltrim($argument, '/');

        if (pathinfo($resolvedPath, PATHINFO_EXTENSION) === '') {
            $resolvedPath .= '.'.$this->fileExtension();
        }

        return $this->validatedRestoreTarget($resolvedPath, 'logical_restore_file');
    }

    private function latestBackupTarget(): string
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
                return $this->validatedRestoreTarget($run->artifact_path, 'logical_restore_latest');
            } catch (ConfigurationException) {
                continue;
            }
        }

        $candidates = glob($this->outputDir().'/'.$this->outputPrefix().'-*.'.$this->fileExtension(), GLOB_NOSORT) ?: [];
        $candidates = array_values(array_filter($candidates, is_file(...)));

        if ($candidates === []) {
            throw new ConfigurationException('No mysql logical backup exports were found for logical_restore_latest.');
        }

        usort($candidates, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return $this->validatedRestoreTarget($candidates[0], 'logical_restore_latest');
    }

    /**
     * @return list<string>
     */
    private function pitrBinlogFiles(): array
    {
        $files = config('checkpoint.drivers.mysql.pitr.binlog_files', []);

        if (! is_array($files)) {
            throw new ConfigurationException('checkpoint.drivers.mysql.pitr.binlog_files must be an array.');
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $files,
        ), static fn (string $value): bool => $value !== ''));

        /** @var list<string> $normalized */
        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function extraArgs(string $key): array
    {
        $value = config("checkpoint.drivers.mysql.extra_args.{$key}", []);

        if (! is_array($value)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.mysql.extra_args.%s must be an array.', $key),
            );
        }

        $args = array_values(array_filter(
            $value,
            static fn (mixed $arg): bool => is_string($arg) && trim($arg) !== '',
        ));

        /** @var list<string> $args */
        return $args;
    }

    private function commandTimeout(): float
    {
        $timeout = (int) config('checkpoint.drivers.mysql.command_timeout_seconds', 7200);

        if ($timeout < 1) {
            throw new ConfigurationException('checkpoint.drivers.mysql.command_timeout_seconds must be greater than zero.');
        }

        return (float) $timeout;
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        return match ($run->operation) {
            'pitr_restore' => implode(' ; ', [
                $this->mysqlRestoreProcess()->getCommandLine(),
                $this->mysqlBinlogProcess((string) ($plannedMetadata['restore_target'] ?? $run->argument_text ?? ''))->getCommandLine(),
                $this->mysqlPitrReplayProcess()->getCommandLine(),
            ]),
            'backup_drill' => trim((string) config('checkpoint.drivers.mysql.drill_command', '')) !== ''
                ? $this->buildProcess($run, $plannedMetadata)->getCommandLine()
                : '(inline structure validation)',
            'replication_sync' => implode(' ; ', array_filter([
                $this->mysqlReplicationDryRunProcess($plannedMetadata)->getCommandLine(),
                (bool) ($plannedMetadata['metadata']['replication']['apply_requested'] ?? false)
                    ? $this->mysqlReplicationApplyProcess()->getCommandLine()
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
                'artifact_path' => $this->backupTarget($run),
                'verification_state' => 'not_applicable',
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->databaseName(),
                ],
            ],
            'logical_restore_latest', 'logical_restore_file' => [
                ...$this->resolvedRestoreTargetMetadata($run),
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->databaseName(),
                    'restore_mode' => 'logical',
                ],
            ],
            'pitr_restore' => [
                'restore_target' => trim((string) ($run->argument_text ?? '')),
                'pitr_base_target' => $this->latestBackupTarget(),
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->databaseName(),
                    'restore_mode' => 'pitr',
                    'binlog_files' => $this->pitrBinlogFiles(),
                ],
            ],
            'replication_sync' => [
                'metadata' => [
                    'driver' => 'mysql',
                    'replication' => $this->replicationPlan($run),
                ],
            ],
            'backup_drill' => [
                'metadata' => [
                    'driver' => 'mysql',
                    'database' => $this->databaseName(),
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array{restore_target:string,restore_target_snapshot:array<string,mixed>}
     */
    private function resolvedRestoreTargetMetadata(CommandRun $run): array
    {
        $target = match ($run->operation) {
            'logical_restore_latest' => $this->latestBackupTarget(),
            'logical_restore_file' => $this->restorePathFromArgument($run),
            default => throw new ConfigurationException(
                sprintf('Unsupported mysql restore operation [%s].', $run->operation),
            ),
        };

        return [
            'restore_target' => $target,
            'restore_target_snapshot' => $this->restoreTargetSnapshot($target),
        ];
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array<string, mixed>
     */
    private function persistedPlannedMetadata(array $plannedMetadata): array
    {
        unset($plannedMetadata['restore_target_snapshot']);
        unset($plannedMetadata['pitr_base_target']);

        return $plannedMetadata;
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $captureMetadata
     * @return array<string, mixed>
     */
    private function completedMetadata(CommandRun $run, array $plannedMetadata, int $exitCode, array $captureMetadata): array
    {
        $metadata = $plannedMetadata['metadata'] ?? ['driver' => 'mysql'];

        if (! is_array($metadata)) {
            $metadata = ['driver' => 'mysql'];
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
                    $completed['metadata']['artifact_snapshot'] = $this->artifactSnapshot($artifactPath);
                }

                $completed['last_known_good_at'] = now();
            }
        }

        if (in_array($run->operation, ['logical_restore_latest', 'logical_restore_file'], true)) {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'restored_via' => 'mysql',
            ];
        }

        if ($run->operation === 'pitr_restore') {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'restored_via' => 'mysqlbinlog',
            ];
        }

        if ($run->operation === 'replication_sync') {
            $completed['metadata'] = [
                ...$completed['metadata'],
                'replicated_via' => 'mysqldump+mysql',
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

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeDryRunReplication(array $plannedMetadata, CommandRun $run): array
    {
        return $this->executeSingleProcess($this->mysqlReplicationDryRunProcess($plannedMetadata));
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    private function executeApplyReplication(string $artifactPath, CommandRun $run): array
    {
        $contents = is_file($artifactPath) ? file_get_contents($artifactPath) : false;

        if (! is_string($contents)) {
            throw new ConfigurationException(sprintf('Unable to read mysql replication staging artifact [%s].', $artifactPath));
        }

        return $this->executeSingleProcess($this->mysqlReplicationApplyProcess(), $contents);
    }

    private function replicationDriverLabel(): string
    {
        return 'mysql';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    private function mysqlReplicationDryRunProcess(array $plannedMetadata): Process
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('mysql replication requires an artifact path for dry-run export.');
        }

        $command = [
            $this->mysqldumpBinary(),
            '--databases',
            $this->databaseName(),
            '--result-file='.$artifactPath,
        ];

        if ((bool) config('checkpoint.drivers.mysql.single_transaction', true)) {
            $command[] = '--single-transaction';
        }

        if ((bool) config('checkpoint.drivers.mysql.quick', true)) {
            $command[] = '--quick';
        }

        if ((bool) config('checkpoint.drivers.mysql.skip_lock_tables', true)) {
            $command[] = '--skip-lock-tables';
        }

        return new Process(
            [...$command, ...$this->extraArgs('backup')],
            timeout: $this->commandTimeout(),
        );
    }

    private function mysqlReplicationApplyProcess(): Process
    {
        return new Process(
            [
                $this->mysqlBinary(),
                '--database='.$this->databaseName(),
                ...$this->extraArgs('restore'),
            ],
            timeout: $this->commandTimeout(),
        );
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

        if ($engine !== 'mysql') {
            throw new ConfigurationException(sprintf(
                'Unsupported replication engine [%s] for mysql driver. mysql driver supports mysql -> mysql only.',
                $engine,
            ));
        }

        return [
            'engine' => 'mysql',
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
            '%s/%s-%d.sql',
            $this->tempDir(),
            'checkpoint-mysql-replication',
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
        $contents = file_get_contents($path);

        return [
            'path' => $path,
            'size' => $size === false ? null : $size,
            'sha1' => is_file($path) ? sha1_file($path) : null,
            'line_count' => is_string($contents) ? substr_count($contents, "\n") + 1 : null,
            'statement_count' => is_string($contents) ? substr_count(strtoupper($contents), ';') : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $expectedSnapshot
     */
    private function validatedRestoreTarget(string $resolvedPath, string $operation, ?array $expectedSnapshot = null): string
    {
        $realOutputDir = realpath($this->outputDir());
        $realTargetPath = realpath($resolvedPath);

        if ($realOutputDir === false) {
            throw new ConfigurationException('Unable to resolve the configured mysql output directory.');
        }

        if ($realTargetPath === false) {
            throw new ConfigurationException(
                sprintf('Configured mysql restore target [%s] does not exist.', $resolvedPath),
            );
        }

        $realOutputDir = rtrim($realOutputDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $realTargetPrefix = rtrim($realTargetPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $isContained = str_starts_with($realTargetPath, $realOutputDir)
            || str_starts_with($realTargetPrefix, $realOutputDir);

        if (! $isContained) {
            throw new ConfigurationException(
                sprintf('%s target [%s] must be inside the configured mysql output directory.', $operation, $resolvedPath),
            );
        }

        if (! is_file($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('%s target [%s] must be a restoreable mysql dump file.', $operation, $resolvedPath),
            );
        }

        $snapshot = $this->restoreTargetSnapshot($realTargetPath);

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
    private function restoreTargetSnapshot(string $realTargetPath): array
    {
        clearstatcache(true, $realTargetPath);

        if (! file_exists($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('Configured mysql restore target [%s] does not exist.', $realTargetPath),
            );
        }

        $stats = @stat($realTargetPath);

        if ($stats === false) {
            throw new ConfigurationException(
                sprintf('Configured mysql restore target [%s] does not exist.', $realTargetPath),
            );
        }

        return [
            'path' => $realTargetPath,
            'file_type' => 'file',
            'device' => (int) $stats['dev'],
            'inode' => (int) $stats['ino'],
            'mtime' => (int) $stats['mtime'],
            'size' => (int) $stats['size'],
            'content_signature' => is_file($realTargetPath) ? sha1_file($realTargetPath) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function artifactSnapshot(string $path): ?array
    {
        try {
            return $this->restoreTargetSnapshot($path);
        } catch (ConfigurationException) {
            return null;
        }
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

    private function pathSize(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }

    private function logger(): LoggerInterface
    {
        return Log::channel(config('checkpoint.log_channel', 'stack'));
    }

    private function redactCommandLine(string $commandLine): string
    {
        return $this->commandLineRedactor()->redact($commandLine);
    }

    private function commandLineRedactor(): CommandLineRedactor
    {
        return resolve(CommandLineRedactor::class);
    }

    private function outputCapture(): CommandOutputCapture
    {
        return resolve(CommandOutputCapture::class);
    }

    private function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }

    private function tapCapturedOutput(): void
    {
        // MySQL multi-stage operations do not use stream sessions; only heartbeat is needed.
        $this->activeRun?->recordHeartbeatIfDue();
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
            'driver' => 'mysql',
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

    private function postRestoreVerificationBuilder(): PostRestoreVerificationBuilder
    {
        return resolve(PostRestoreVerificationBuilder::class);
    }

    private function uploadArtifact(CommandRun $run): void
    {
        $disk = (string) config('checkpoint.disk', '');

        if ($disk === '') {
            return;
        }

        $artifactPath = $this->backupTarget($run);

        $uploadMetadata = (new BackupArtifactUploader)->upload($artifactPath, $disk, 'checkpoint/mysql-exports');

        if ($uploadMetadata !== null) {
            $run->recordMetadata([
                'metadata' => [
                    'upload' => $uploadMetadata,
                ],
            ]);
        }
    }
}
