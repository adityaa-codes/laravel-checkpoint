<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** @internal */
final readonly class PostgresRestoreTargetResolver
{
    public function __construct(
        private PostgresDriverConfig $config,
        private PostgresSnapshotService $snapshot,
        private Filesystem $filesystem,
    ) {}

    public function resolveForRestoreWithFormat(CommandRun $run, PostgresFormat $format): string
    {
        return match ($run->operation) {
            'logical_restore_latest' => $this->latestBackupTarget($format),
            'logical_restore_file' => $this->restorePathFromArgument($run, $format),
            default => throw ConfigurationException::unsupportedOperation($run->operation, 'Postgres restore'),
        };
    }

    public function latestBackupTarget(PostgresFormat $format): string
    {
        $trackedTarget = $this->latestTrackedBackupTarget($format);

        if ($trackedTarget !== null) {
            return $trackedTarget;
        }

        $candidates = $this->filesystem->glob($this->config->outputDir.'/'.$this->config->outputPrefix.'-*', GLOB_NOSORT) ?: [];
        $candidates = collect($candidates)
            ->filter(fn (string $candidate): bool => $format === PostgresFormat::Directory
                ? $this->filesystem->isDirectory($candidate)
                : $this->filesystem->isFile($candidate))
            ->values()
            ->all();

        if ($candidates === []) {
            throw ConfigurationException::noBackupExportsFound();
        }

        $candidates = collect($candidates)
            ->sortByDesc(fn (string $path): int => $this->filesystem->lastModified($path))
            ->values()
            ->all();

        return $this->validatedRestoreTarget($candidates[0], $format);
    }

    public function backupTarget(CommandRun $run): string
    {
        $basePath = $this->config->outputDir.'/'.$this->config->outputPrefix.'-'.$run->getKey();

        return $this->config->format === PostgresFormat::Directory
            ? $basePath
            : $basePath.'.'.$this->config->fileExtension;
    }

    /**
     * @return array{restore_target:string,restore_target_snapshot:array<string,mixed>}
     */
    public function resolvedRestoreTargetMetadata(CommandRun $run, PostgresFormat $format): array
    {
        $target = $this->resolveForRestoreWithFormat($run, $format);

        return [
            'restore_target' => $target,
            'restore_target_snapshot' => $this->snapshot->snapshot($target, $format),
        ];
    }

    public function validatedRestoreTarget(string $resolvedPath, PostgresFormat $format, ?array $expectedSnapshot = null): string
    {
        $realOutputDir = realpath($this->config->outputDir);
        $realTargetPath = realpath($resolvedPath);

        if ($realOutputDir === false) {
            throw ConfigurationException::cannotResolveDirectory('postgres output');
        }

        if ($realTargetPath === false) {
            throw ConfigurationException::targetNotFound($resolvedPath);
        }

        $realOutputDir = Str::finish($realOutputDir, '/');
        $realTargetPrefix = Str::finish($realTargetPath, '/');
        $isContained = Str::startsWith($realTargetPath, $realOutputDir)
            || Str::startsWith($realTargetPrefix, $realOutputDir);

        if (! $isContained) {
            throw ConfigurationException::targetEscapesOutputDir($resolvedPath);
        }

        if ($format === PostgresFormat::Directory && ! $this->filesystem->isDirectory($realTargetPath)) {
            throw ConfigurationException::targetNotDirectory($resolvedPath);
        }

        if ($format === PostgresFormat::Custom && ! $this->filesystem->isFile($realTargetPath)) {
            throw ConfigurationException::targetNotFile($resolvedPath);
        }

        $currentSnapshot = $this->snapshot->snapshot($realTargetPath, $format);

        if ($expectedSnapshot !== null && $this->snapshot->snapshotChanged($currentSnapshot, $expectedSnapshot)) {
            throw ConfigurationException::targetChangedAfterValidation($resolvedPath);
        }

        return $realTargetPath;
    }

    private function latestTrackedBackupTarget(PostgresFormat $format): ?string
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
            if ($run->artifact_path === null || trim($run->artifact_path) === '') {
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

    private function restorePathFromArgument(CommandRun $run, PostgresFormat $format): string
    {
        $argument = trim($run->argument_text ?? '');

        if ($argument === '') {
            throw ConfigurationException::missingRestoreArgument();
        }

        $resolvedPath = Str::startsWith($argument, '/')
            ? $argument
            : $this->config->outputDir.'/'.Str::ltrim($argument, '/');

        if ($format === PostgresFormat::Custom && pathinfo($resolvedPath, PATHINFO_EXTENSION) === '') {
            $resolvedPath .= '.'.$this->config->fileExtension;
        }

        return $this->validatedRestoreTarget($resolvedPath, $format);
    }
}
