<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Enums\PostgresFormat;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

final class PostgresSnapshotService
{
    public function __construct(
        private Filesystem $filesystem,
    ) {}

    /**
     * @return array{path:string,file_type:string,device:int|null,inode:int|null,mtime:int|null,size:int|null,content_signature:string|null}
     */
    public function snapshot(string $realTargetPath, PostgresFormat $format): array
    {
        clearstatcache(true, $realTargetPath);

        if (! $this->filesystem->exists($realTargetPath)) {
            throw ConfigurationException::targetNotFound($realTargetPath);
        }

        $stats = stat($realTargetPath);

        if ($stats === false) {
            throw ConfigurationException::targetNotFound($realTargetPath);
        }

        return [
            'path' => $realTargetPath,
            'file_type' => $format->value,
            'device' => $stats['dev'],
            'inode' => $stats['ino'],
            'mtime' => $stats['mtime'],
            'size' => $format === PostgresFormat::Custom ? $stats['size'] : null,
            'content_signature' => $format === PostgresFormat::Directory ? $this->contentSignature($realTargetPath) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function safeSnapshot(string $path, PostgresFormat $format): ?array
    {
        try {
            return $this->snapshot($path, $format);
        } catch (ConfigurationException) {
            return null;
        }
    }

    public function pathSize(string $path): ?int
    {
        if ($this->filesystem->isFile($path)) {
            return $this->filesystem->size($path);
        }

        if (! $this->filesystem->isDirectory($path)) {
            return null;
        }

        return collect($this->filesystem->allFiles($path))
            ->sum(fn (\SplFileInfo $file): int => $file->getSize());
    }

    public function contentSignature(string $path): string
    {
        $entries = collect();
        $baseLength = Str::length(Str::rtrim($path, DIRECTORY_SEPARATOR)) + 1;

        foreach ($this->filesystem->allFiles($path) as $file) {
            $entries->push(collect([
                Str::substr($file->getPathname(), $baseLength),
                'file',
                $file->getInode(),
                $file->getSize(),
                $file->getMTime(),
            ])->join('|'));
        }

        foreach ($this->filesystem->directories($path) as $directory) {
            $entries->push(collect([
                Str::substr($directory, $baseLength),
                'dir',
                (string) (stat($directory)['ino'] ?? '0'),
                '0',
                (string) $this->filesystem->lastModified($directory),
            ])->join('|'));
        }

        return hash('sha256', $entries->sort()->join("\n"));
    }

    public function snapshotChanged(array $current, array $expected): bool
    {
        foreach (['path', 'file_type', 'device', 'inode', 'mtime', 'size', 'content_signature'] as $key) {
            if (($current[$key] ?? null) !== ($expected[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
