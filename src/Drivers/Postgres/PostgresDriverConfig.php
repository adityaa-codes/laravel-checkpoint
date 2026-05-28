<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Enums\PostgresPhysicalCheckpoint;
use AdityaaCodes\LaravelCheckpoint\Enums\PostgresPhysicalCompression;
use AdityaaCodes\LaravelCheckpoint\Enums\PostgresWalMethod;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;

/** @internal */
final readonly class PostgresDriverConfig
{
    /**
     * @param  list<string>  $extraArgsBackup
     * @param  list<string>  $extraArgsRestore
     * @param  list<string>  $extraArgsDrill
     * @param  list<string>  $extraArgsReplication
     * @param  list<string>  $physicalExtraArgs
     */
    public function __construct(
        public string $dumpBinary,
        public string $restoreBinary,
        public PostgresFormat $format,
        public int $jobs,
        public int $compressLevel,
        public string $outputDir,
        public string $outputPrefix,
        public string $fileExtension,
        public bool $clean,
        public bool $create,
        public string $drillCommand,
        public array $extraArgsBackup,
        public array $extraArgsRestore,
        public array $extraArgsDrill,
        public array $extraArgsReplication,
        public float $commandTimeoutSeconds,
        public string $physicalBinary,
        public string $physicalOutputDir,
        public array $physicalExtraArgs,
        public PostgresWalMethod $physicalWalMethod,
        public PostgresPhysicalCompression $physicalCompression,
        public PostgresPhysicalCheckpoint $physicalCheckpoint,
        public ?string $physicalMaxRate,
        public string $logChannel,
        public string $pitrWalDirectory,
        public string $pitrRestoreCommand,
        public string $pitrRecoveryTargetAction,
        public string $databaseName,
        private Filesystem $filesystem,
        private Repository $config,
    ) {
        if ($this->jobs < 1) {
            throw ConfigurationException::mustBePositive('checkpoint.drivers.postgres.jobs');
        }
        if ($this->compressLevel < 0 || $this->compressLevel > 9) {
            throw ConfigurationException::mustBeBetween('checkpoint.drivers.postgres.compress_level', 0, 9);
        }
        if ($this->outputDir === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.postgres.output_dir');
        }
        if ($this->outputPrefix === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.postgres.output_prefix');
        }
        if ($this->fileExtension === '') {
            throw ConfigurationException::mustNotBeEmpty('checkpoint.drivers.postgres.file_extension');
        }
        if ($this->commandTimeoutSeconds < 1) {
            throw ConfigurationException::mustBePositive('checkpoint.drivers.postgres.command_timeout_seconds');
        }
        if ($this->databaseName === '') {
            throw ConfigurationException::mustNotBeEmpty('The default database connection must define a database name for Postgres operations');
        }

        $this->ensureOutputDirExists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Filesystem $filesystem, string $databaseName, Repository $config): self
    {
        $binaryPath = rtrim((string) $config->get('database.connections.pgsql.dump.dump_binary_path', ''), '/');

        return new self(
            dumpBinary: $binaryPath !== '' ? $binaryPath.'/pg_dump' : 'pg_dump',
            restoreBinary: $binaryPath !== '' ? $binaryPath.'/pg_restore' : 'pg_restore',
            format: PostgresFormat::from($data['format'] ?? 'directory'),
            jobs: $data['jobs'] ?? 4,
            compressLevel: $data['compress_level'] ?? 6,
            outputDir: $data['output_dir'] ?? '',
            outputPrefix: $data['output_prefix'] ?? '',
            fileExtension: $data['file_extension'] ?? '',
            clean: $data['clean'] ?? true,
            create: $data['create'] ?? false,
            drillCommand: $data['drill_command'] ?? '',
            extraArgsBackup: $data['extra_args']['backup'] ?? [],
            extraArgsRestore: $data['extra_args']['restore'] ?? [],
            extraArgsDrill: $data['extra_args']['drill'] ?? [],
            extraArgsReplication: $data['extra_args']['replication'] ?? [],
            commandTimeoutSeconds: $data['command_timeout_seconds'] ?? 7200,
            physicalBinary: $binaryPath !== '' ? $binaryPath.'/pg_basebackup' : 'pg_basebackup',
            physicalOutputDir: $data['physical_output_dir'] ?? '',
            physicalExtraArgs: $data['physical_extra_args'] ?? [],
            physicalWalMethod: PostgresWalMethod::from($data['physical_wal_method'] ?? 'stream'),
            physicalCompression: PostgresPhysicalCompression::from($data['physical_compression'] ?? 'gzip'),
            physicalCheckpoint: PostgresPhysicalCheckpoint::from($data['physical_checkpoint'] ?? 'fast'),
            physicalMaxRate: $data['physical_max_rate'] ?? null,
            pitrWalDirectory: $data['pitr']['wal_directory'] ?? '',
            pitrRestoreCommand: $data['pitr']['restore_command'] ?? '',
            pitrRecoveryTargetAction: $data['pitr']['recovery_target_action'] ?? 'promote',
            logChannel: $data['log_channel'] ?? 'stack',
            databaseName: $databaseName,
            filesystem: $filesystem,
            config: $config,
        );
    }

    /**
     * @return list<string>
     */
    public function extraArgs(string $key): array
    {
        return match ($key) {
            'backup' => $this->extraArgsBackup,
            'restore' => $this->extraArgsRestore,
            'drill' => $this->extraArgsDrill,
            'replication' => $this->extraArgsReplication,
            default => throw ConfigurationException::notAnArray($key),
        };
    }

    /**
     * @return list<string>
     */
    public function connectionArgs(): array
    {
        $args = [];
        $connection = $this->config->get('database.connections.pgsql', []);

        $host = $connection['host'] ?? null;
        $port = $connection['port'] ?? null;
        $username = $connection['username'] ?? null;

        if (is_string($host) && $host !== '') {
            $args[] = '--host='.$host;
        }

        if ($port !== null) {
            $args[] = '--port='.(string) $port;
        }

        if (is_string($username) && $username !== '') {
            $args[] = '--username='.$username;
        }

        return $args;
    }

    private function ensureOutputDirExists(): void
    {
        $this->ensureDir($this->outputDir, 0755, 'postgres output');
    }

    public function ensurePhysicalOutputDirExists(): void
    {
        $this->ensureDir($this->physicalOutputDir, 0700, 'postgres physical output');
    }

    private function ensureDir(string $path, int $mode, string $label): void
    {
        if (! $this->filesystem->makeDirectory($path, $mode, true, true) && ! $this->filesystem->isDirectory($path)) {
            throw ConfigurationException::directoryCreationFailed($label, $path);
        }
    }
}
