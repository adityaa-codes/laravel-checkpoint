<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresPhysicalRestoreHandler implements PostgresSelfExecutingHandler
{
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    public function supports(string $operation): bool
    {
        return $operation === 'physical_restore';
    }

    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        return new Process(['true']);
    }

    public function execute(CommandRun $run, array $plannedMetadata): array
    {
        $target = trim($run->argument_text ?? '');

        if ($target === '') {
            throw ConfigurationException::physicalRestoreRequiresArgument();
        }

        if (! $this->filesystem->isDirectory($target)) {
            throw ConfigurationException::physicalRestoreTargetNotFound($target);
        }

        if (! $this->filesystem->isFile($target.'/base.tar.gz')) {
            throw ConfigurationException::physicalRestoreMissingBaseTar($target);
        }

        throw ConfigurationException::physicalRestoreManualOnly();
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        return sprintf('tar -xzf %s/base.tar.gz -C <pgdata>', trim($run->argument_text ?? ''));
    }

    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'metadata' => [
                'driver' => 'postgres',
            ],
        ];
    }
}
