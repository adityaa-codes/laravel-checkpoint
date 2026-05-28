<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use Illuminate\Filesystem\Filesystem;

final class MysqlRestoreTargetValidator
{
    public function __construct(
        private readonly MysqlConfiguration $config,
        private readonly Filesystem $filesystem,
    ) {}

    /**
     * @param  array<string, mixed>|null  $expectedSnapshot
     */
    public function validatedRestoreTarget(string $resolvedPath, string $operation, ?array $expectedSnapshot = null): string
    {
        $realOutputDir = realpath($this->config->outputDir());
        $realTargetPath = realpath($resolvedPath);

        if ($realOutputDir === false) {
            throw ConfigurationException::cannotResolveDirectory('mysql output');
        }

        if ($realTargetPath === false) {
            throw ConfigurationException::targetNotFound($resolvedPath);
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
            throw ConfigurationException::targetNotFile($resolvedPath);
        }

        $snapshot = $this->restoreTargetSnapshot($realTargetPath);

        if ($expectedSnapshot !== null && $this->restoreTargetChanged($snapshot, $expectedSnapshot)) {
            throw ConfigurationException::targetChangedAfterValidation($resolvedPath);
        }

        return $realTargetPath;
    }

    public function restorePathFromArgument(DriverContext $context, CommandRun $run): string
    {
        $argument = trim((string) ($context->argument ?? ''));

        if ($argument === '') {
            throw ConfigurationException::missingRestoreArgument();
        }

        $resolvedPath = str_starts_with($argument, '/')
            ? $argument
            : $this->config->outputDir().'/'.ltrim($argument, '/');

        if (pathinfo($resolvedPath, PATHINFO_EXTENSION) === '') {
            $resolvedPath .= '.'.$this->config->fileExtension();
        }

        return $this->validatedRestoreTarget($resolvedPath, 'logical_restore_file');
    }

    public function latestBackupTarget(): string
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
            if (trim($run->artifact_path) === '') {
                continue;
            }
            try {
                return $this->validatedRestoreTarget($run->artifact_path, 'logical_restore_latest');
            } catch (ConfigurationException) {
                continue;
            }
        }

        $candidates = glob(
            $this->config->outputDir().'/'.$this->config->outputPrefix().'-*.'.$this->config->fileExtension(),
            GLOB_NOSORT,
        ) ?: [];
        $candidates = array_values(array_filter($candidates, is_file(...)));

        if ($candidates === []) {
            throw ConfigurationException::noBackupExportsFound();
        }

        usort($candidates, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return $this->validatedRestoreTarget($candidates[0], 'logical_restore_latest');
    }

    /**
     * @return array{path:string,file_type:string,device:int|null,inode:int|null,mtime:int|null,size:int|null,content_signature:string|null}
     */
    public function restoreTargetSnapshot(string $realTargetPath): array
    {
        clearstatcache(true, $realTargetPath);

        if (! file_exists($realTargetPath)) {
            throw ConfigurationException::targetNotFound($realTargetPath);
        }

        $stats = stat($realTargetPath);

        if ($stats === false) {
            throw ConfigurationException::targetNotFound($realTargetPath);
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
    public function artifactSnapshot(string $path): ?array
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
    public function restoreTargetChanged(array $currentSnapshot, array $expectedSnapshot): bool
    {
        foreach (['path', 'file_type', 'device', 'inode', 'mtime', 'size', 'content_signature'] as $key) {
            if (($currentSnapshot[$key] ?? null) !== ($expectedSnapshot[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function pathSize(string $path): ?int
    {
        if (! is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }
}
