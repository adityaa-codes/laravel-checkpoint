<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** @internal */
final class PostgresPhysicalRestoreHandler implements PostgresOperationHandler
{
    public function supports(string $operation): bool
    {
        return $operation === 'physical_restore';
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     */
    public function buildProcess(CommandRun $run, array $plannedMetadata): Process
    {
        $target = Str::trim((string) ($run->argument_text ?? ''));

        if ($target === '') {
            throw new ConfigurationException('physical_restore requires a backup directory path as argument.');
        }

        if (! File::isDirectory($target)) {
            throw new ConfigurationException(
                sprintf('Physical backup target directory [%s] does not exist.', $target),
            );
        }

        if (! File::isFile($target.'/base.tar.gz')) {
            throw new ConfigurationException(
                sprintf('Physical backup target [%s] does not contain base.tar.gz.', $target),
            );
        }

        throw new ConfigurationException(
            'Postgres physical restore requires the PostgreSQL server to be stopped before restoring. '
            .'Stop the server, then run: tar -xzf '.$target.'/base.tar.gz -C <pgdata>. '
            .'Once complete, start the server and enqueue the restore as a completed CommandRun.',
        );
    }

    public function execute(CommandRun $run, array $plannedMetadata): ?array
    {
        return null;
    }

    public function displayCommandLine(CommandRun $run, array $plannedMetadata): string
    {
        return sprintf('tar -xzf %s/base.tar.gz -C <pgdata>', Str::trim((string) ($run->argument_text ?? '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function plannedMetadata(CommandRun $run): array
    {
        return [
            'metadata' => [
                'driver' => 'postgres',
            ],
        ];
    }
}
