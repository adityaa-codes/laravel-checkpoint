<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** @internal */
final class PostgresRestoreTargetResolver
{
    public function __construct(
        private readonly PostgresDriverConfig $config,
        private readonly PostgresSnapshotService $snapshot,
    ) {}

    public function resolveForRestoreWithFormat(CommandRun $run, string $format): string
    {
        return match ($run->operation) {
            'logical_restore_latest' => $this->latestBackupTarget($format),
            'logical_restore_file' => $this->restorePathFromArgument($run, $format),
            default => throw new ConfigurationException(
                sprintf('Unsupported Postgres restore operation [%s].', $run->operation),
            ),
        };
    }

    public function latestBackupTarget(string $format): string
    {
        $trackedTarget = $this->latestTrackedBackupTarget($format);

        if ($trackedTarget !== null) {
            return $trackedTarget;
        }

        $candidates = File::glob($this->config->outputDir.'/'.$this->config->outputPrefix.'-*', GLOB_NOSORT) ?: [];
        $candidates = collect($candidates)
            ->filter(fn (string $candidate): bool => $format === 'directory' ? File::isDirectory($candidate) : File::isFile($candidate))
            ->values()
            ->all();

        if ($candidates === []) {
            throw new ConfigurationException('No logical backup exports were found for logical_restore_latest.');
        }

        $candidates = collect($candidates)
            ->sortByDesc(fn (string $path): int => File::lastModified($path))
            ->values()
            ->all();

        return $this->validatedRestoreTarget($candidates[0], $format);
    }

    public function backupTarget(CommandRun $run): string
    {
        $basePath = $this->config->outputDir.'/'.$this->config->outputPrefix.'-'.$run->getKey();

        return $this->config->format === 'directory'
            ? $basePath
            : $basePath.'.'.$this->config->fileExtension;
    }

    /**
     * @return array{restore_target:string,restore_target_snapshot:array<string,mixed>}
     */
    public function resolvedRestoreTargetMetadata(CommandRun $run, string $format): array
    {
        $target = $this->resolveForRestoreWithFormat($run, $format);

        return [
            'restore_target' => $target,
            'restore_target_snapshot' => $this->snapshot->snapshot($target, $format),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $expectedSnapshot
     */
    public function validatedRestoreTarget(string $resolvedPath, string $format, ?array $expectedSnapshot = null): string
    {
        $realOutputDir = realpath($this->config->outputDir);
        $realTargetPath = realpath($resolvedPath);

        if ($realOutputDir === false) {
            throw new ConfigurationException('Unable to resolve the configured postgres output directory.');
        }

        if ($realTargetPath === false) {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $resolvedPath),
            );
        }

        $realOutputDir = Str::finish($realOutputDir, '/');
        $realTargetPrefix = Str::finish($realTargetPath, '/');
        $isContained = Str::startsWith($realTargetPath, $realOutputDir)
            || Str::startsWith($realTargetPrefix, $realOutputDir);

        if (! $isContained) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be inside the configured postgres output directory.', $resolvedPath),
            );
        }

        if ($format === 'directory' && ! File::isDirectory($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be a directory export.', $resolvedPath),
            );
        }

        if ($format === 'custom' && ! File::isFile($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('logical_restore_file target [%s] must be a restoreable file export.', $resolvedPath),
            );
        }

        $currentSnapshot = $this->snapshot->snapshot($realTargetPath, $format);

        if ($expectedSnapshot !== null && $this->snapshot->snapshotChanged($currentSnapshot, $expectedSnapshot)) {
            throw new ConfigurationException(
                sprintf('logical restore target [%s] changed after validation and must be selected again.', $resolvedPath),
            );
        }

        return $realTargetPath;
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
            if (Str::trim((string) $run->artifact_path) === '') {
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
        $argument = Str::trim((string) ($run->argument_text ?? ''));

        if ($argument === '') {
            throw new ConfigurationException('logical_restore_file requires a backup path or export name.');
        }

        $resolvedPath = Str::startsWith($argument, '/')
            ? $argument
            : $this->config->outputDir.'/'.Str::ltrim($argument, '/');

        if ($format === 'custom' && File::extension($resolvedPath) === '') {
            $resolvedPath .= '.'.$this->config->fileExtension;
        }

        return $this->validatedRestoreTarget($resolvedPath, $format);
    }
}
