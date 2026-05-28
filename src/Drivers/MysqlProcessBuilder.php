<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Symfony\Component\Process\Process;

final class MysqlProcessBuilder
{
    public function __construct(
        private readonly MysqlConfiguration $config,
    ) {}

    public function mysqlBackupProcess(CommandRun $run): Process
    {
        $command = [
            $this->config->mysqldumpBinary(),
            '--databases',
            $this->config->databaseName(),
            '--result-file='.$this->config->backupTarget($run),
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
            [...$command, ...$this->config->extraArgs('backup')],
            timeout: $this->config->commandTimeout(),
        );
    }

    public function mysqlRestoreProcess(): Process
    {
        return new Process(
            [
                $this->config->mysqlBinary(),
                '--database='.$this->config->databaseName(),
                ...$this->config->extraArgs('restore'),
            ],
            timeout: $this->config->commandTimeout(),
        );
    }

    public function mysqlBinlogProcess(string $targetTime, ?string $resultFile = null): Process
    {
        $trimmedTarget = trim($targetTime);

        if ($trimmedTarget === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        $files = $this->config->pitrBinlogFiles();

        if ($files === []) {
            throw new ConfigurationException(
                'checkpoint.drivers.mysql.pitr.binlog_files must list at least one binary log for pitr_restore.',
            );
        }

        $command = [
            $this->config->mysqlbinlogBinary(),
            '--stop-datetime='.$trimmedTarget,
            ...$this->config->extraArgs('pitr_binlog'),
        ];

        if ($resultFile !== null && trim($resultFile) !== '') {
            $command[] = '--result-file='.$resultFile;
        }

        return new Process(
            [...$command, ...$files],
            timeout: $this->config->commandTimeout(),
        );
    }

    public function mysqlPitrReplayProcess(): Process
    {
        return new Process(
            [
                $this->config->mysqlBinary(),
                '--binary-mode',
                '--database='.$this->config->databaseName(),
                ...$this->config->extraArgs('pitr_replay'),
            ],
            timeout: $this->config->commandTimeout(),
        );
    }

    public function mysqlDrillProcess(DriverContext $context, CommandRun $run): Process
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
            '{db}' => $this->config->databaseName(),
            '{target}' => trim((string) ($context->argument ?? '')),
            '{backup_dir}' => $this->config->outputDir(),
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
            [...$command, ...$this->config->extraArgs('drill')],
            timeout: $this->config->commandTimeout(),
        );
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function mysqlReplicationDryRunProcess(array $plannedMetadata): Process
    {
        $replication = is_array($plannedMetadata['metadata']['replication'] ?? null) ? $plannedMetadata['metadata']['replication'] : [];
        $artifactPath = (string) ($replication['artifact_path'] ?? '');

        if ($artifactPath === '') {
            throw new ConfigurationException('mysql replication requires an artifact path for dry-run export.');
        }

        $command = [
            $this->config->mysqldumpBinary(),
            '--databases',
            $this->config->databaseName(),
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
            [...$command, ...$this->config->extraArgs('backup')],
            timeout: $this->config->commandTimeout(),
        );
    }

    public function mysqlReplicationApplyProcess(): Process
    {
        return new Process(
            [
                $this->config->mysqlBinary(),
                '--database='.$this->config->databaseName(),
                ...$this->config->extraArgs('restore'),
            ],
            timeout: $this->config->commandTimeout(),
        );
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(DriverContext $context, CommandRun $run, array $plannedMetadata = []): Process
    {
        return match ($context->operation) {
            'logical_backup' => $this->mysqlBackupProcess($run),
            'logical_restore_file', 'logical_restore_latest' => $this->mysqlRestoreProcess(),
            'pitr_restore' => $this->mysqlBinlogProcess((string) ($plannedMetadata['restore_target'] ?? $context->argument ?? '')),
            'replication_sync' => $this->mysqlReplicationDryRunProcess($plannedMetadata),
            'backup_drill' => $this->mysqlDrillProcess($context, $run),
            default => throw ConfigurationException::unsupportedOperation($context->operation, 'mysql'),
        };
    }
}
