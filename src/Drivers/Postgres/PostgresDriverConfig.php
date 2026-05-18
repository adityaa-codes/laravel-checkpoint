<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\File;

/** @internal */
final class PostgresDriverConfig
{
    public function __construct(
        public readonly string $dumpBinary,
        public readonly string $restoreBinary,
        public readonly string $format,
        public readonly int $jobs,
        public readonly int $compressLevel,
        public readonly string $outputDir,
        public readonly string $outputPrefix,
        public readonly string $fileExtension,
        public readonly bool $clean,
        public readonly bool $create,
        public readonly string $drillCommand,
        /** @var list<string> */
        public readonly array $extraArgsBackup,
        /** @var list<string> */
        public readonly array $extraArgsRestore,
        /** @var list<string> */
        public readonly array $extraArgsDrill,
        public readonly float $commandTimeoutSeconds,
        public readonly string $physicalBinary,
        public readonly string $physicalOutputDir,
        public readonly string $logChannel,
        public readonly string $databaseName,
    ) {
        if ($this->dumpBinary === '') {
            throw new ConfigurationException('checkpoint.drivers.postgres.dump_binary must be a non-empty string.');
        }
        if ($this->restoreBinary === '') {
            throw new ConfigurationException('checkpoint.drivers.postgres.restore_binary must be a non-empty string.');
        }
        if (! in_array($this->format, ['directory', 'custom'], true)) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.postgres.format [%s] must be directory or custom.', $this->format),
            );
        }
        if ($this->jobs < 1) {
            throw new ConfigurationException('checkpoint.drivers.postgres.jobs must be greater than zero.');
        }
        if ($this->compressLevel < 0 || $this->compressLevel > 9) {
            throw new ConfigurationException('checkpoint.drivers.postgres.compress_level must be between 0 and 9.');
        }
        if ($this->outputDir === '') {
            throw new ConfigurationException('checkpoint.drivers.postgres.output_dir must be a non-empty string.');
        }
        if ($this->outputPrefix === '') {
            throw new ConfigurationException('checkpoint.drivers.postgres.output_prefix must be a non-empty string.');
        }
        if ($this->fileExtension === '') {
            throw new ConfigurationException('checkpoint.drivers.postgres.file_extension must be a non-empty string.');
        }
        if ($this->commandTimeoutSeconds < 1) {
            throw new ConfigurationException('checkpoint.drivers.postgres.command_timeout_seconds must be greater than zero.');
        }
        if ($this->databaseName === '') {
            throw new ConfigurationException('The default database connection must define a database name for Postgres operations.');
        }

        $this->ensureOutputDirExists();
    }

    /**
     * @return list<string>
     */
    public function extraArgs(string $key): array
    {
        $args = match ($key) {
            'backup' => $this->extraArgsBackup,
            'restore' => $this->extraArgsRestore,
            'drill' => $this->extraArgsDrill,
            default => throw new ConfigurationException(
                sprintf('checkpoint.drivers.postgres.extra_args.%s must be an array.', $key),
            ),
        };

        /** @var list<string> $args */
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
        if (! File::makeDirectory($path, $mode, true, true) && ! File::isDirectory($path)) {
            throw new ConfigurationException(
                sprintf('Unable to create %s directory [%s].', $label, $path),
            );
        }
    }
}
