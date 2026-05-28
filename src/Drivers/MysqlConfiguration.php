<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

final class MysqlConfiguration
{
    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $filesystem,
    ) {}

    public function mysqldumpBinary(): string
    {
        $binaryPath = rtrim((string) $this->config->get('database.connections.mysql.dump.dump_binary_path', ''), '/');

        return $binaryPath !== '' ? $binaryPath.'/mysqldump' : 'mysqldump';
    }

    public function mysqlBinary(): string
    {
        $binaryPath = rtrim((string) $this->config->get('database.connections.mysql.dump.dump_binary_path', ''), '/');

        return $binaryPath !== '' ? $binaryPath.'/mysql' : 'mysql';
    }

    public function mysqlbinlogBinary(): string
    {
        $binaryPath = rtrim((string) $this->config->get('database.connections.mysql.dump.dump_binary_path', ''), '/');

        return $binaryPath !== '' ? $binaryPath.'/mysqlbinlog' : 'mysqlbinlog';
    }

    public function databaseName(): string
    {
        $database = trim((string) $this->config->get(
            'database.connections.'.$this->config->get('database.default', '').'.database',
            '',
        ));

        if ($database === '') {
            throw new ConfigurationException('The default database connection must define a database name for mysql operations.');
        }

        return $database;
    }

    public function outputDir(): string
    {
        $outputDir = trim((string) $this->config->get(
            'checkpoint.drivers.mysql.output_dir',
            storage_path('app/checkpoint/mysql/logical-exports'),
        ));

        if ($outputDir === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.mysql.output_dir');
        }

        if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
            throw ConfigurationException::directoryCreationFailed('mysql output', $outputDir);
        }

        return rtrim($outputDir, '/');
    }

    public function tempDir(): string
    {
        $configured = trim((string) $this->config->get('checkpoint.temp_dir', storage_path('app/checkpoint/tmp')));

        if ($configured === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.temp_dir');
        }

        if (file_exists($configured) && ! is_dir($configured)) {
            throw ConfigurationException::directoryCreationFailed('checkpoint temp', $configured);
        }

        if (! is_dir($configured) && ! $this->filesystem->makeDirectory($configured, 0700, true, true) && ! is_dir($configured)) {
            throw ConfigurationException::directoryCreationFailed('checkpoint temp', $configured);
        }

        return $configured;
    }

    public function outputPrefix(): string
    {
        $prefix = trim((string) $this->config->get('checkpoint.drivers.mysql.output_prefix', 'mysql-export'));

        if ($prefix === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.mysql.output_prefix');
        }

        return $prefix;
    }

    public function fileExtension(): string
    {
        $extension = trim((string) $this->config->get('checkpoint.drivers.mysql.file_extension', 'sql'), '.');

        if ($extension === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.mysql.file_extension');
        }

        return $extension;
    }

    public function backupTarget(CommandRun $run): string
    {
        return sprintf(
            '%s/%s-%d.%s',
            $this->outputDir(),
            $this->outputPrefix(),
            (int) $run->getKey(),
            $this->fileExtension(),
        );
    }

    public function replicationArtifactPath(CommandRun $run): string
    {
        return sprintf(
            '%s/%s-%d.sql',
            $this->tempDir(),
            'checkpoint-mysql-replication',
            (int) $run->getKey(),
        );
    }

    /**
     * @return list<string>
     */
    public function pitrBinlogFiles(): array
    {
        $files = $this->config->get('checkpoint.drivers.mysql.pitr.binlog_files', []);

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
    public function extraArgs(string $key): array
    {
        $value = $this->config->get("checkpoint.drivers.mysql.extra_args.{$key}", []);

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

    public function drillCommand(): string
    {
        return trim((string) $this->config->get('checkpoint.drivers.mysql.drill_command', ''));
    }

    public function commandTimeout(): float
    {
        $timeout = (int) $this->config->get('checkpoint.drivers.mysql.command_timeout_seconds', 7200);

        if ($timeout < 1) {
            throw ConfigurationException::mustBePositive('checkpoint.drivers.mysql.command_timeout_seconds');
        }

        return (float) $timeout;
    }
}
