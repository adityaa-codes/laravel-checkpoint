<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** @internal */
final class PostgresSnapshotService
{
    /**
     * @return array{path:string,file_type:string,device:int|null,inode:int|null,mtime:int|null,size:int|null,content_signature:string|null}
     */
    public function snapshot(string $realTargetPath, string $format): array
    {
        clearstatcache(true, $realTargetPath);

        if (! File::exists($realTargetPath)) {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $realTargetPath),
            );
        }

        $stats = stat($realTargetPath);

        if ($stats === false) {
            throw new ConfigurationException(
                sprintf('Configured logical restore target [%s] does not exist.', $realTargetPath),
            );
        }

        return [
            'path' => $realTargetPath,
            'file_type' => $format,
            'device' => (int) $stats['dev'],
            'inode' => (int) $stats['ino'],
            'mtime' => (int) $stats['mtime'],
            'size' => $format === 'custom' ? (int) $stats['size'] : null,
            'content_signature' => $format === 'directory' ? $this->contentSignature($realTargetPath) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function safeSnapshot(string $path, string $format): ?array
    {
        try {
            return $this->snapshot($path, $format);
        } catch (ConfigurationException) {
            return null;
        }
    }

    public function pathSize(string $path): ?int
    {
        if (File::isFile($path)) {
            return File::size($path);
        }

        if (! File::isDirectory($path)) {
            return null;
        }

        return collect(File::allFiles($path))
            ->sum(fn (\SplFileInfo $file): int => $file->getSize());
    }

    public function contentSignature(string $path): string
    {
        $entries = collect();
        $baseLength = Str::length(Str::rtrim($path, DIRECTORY_SEPARATOR)) + 1;

        foreach (File::allFiles($path) as $file) {
            $entries->push(collect([
                Str::substr($file->getPathname(), $baseLength),
                'file',
                $file->getInode(),
                $file->getSize(),
                $file->getMTime(),
            ])->join('|'));
        }

        foreach (File::directories($path) as $directory) {
            $entries->push(collect([
                Str::substr($directory, $baseLength),
                'dir',
                (string) (stat($directory)['ino'] ?? '0'),
                '0',
                (string) File::lastModified($directory),
            ])->join('|'));
        }

        return hash('sha1', $entries->sort()->join("\n"));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $expected
     */
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
