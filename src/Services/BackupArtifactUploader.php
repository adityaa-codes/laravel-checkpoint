<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class BackupArtifactUploader
{
    /**
     * @return array<string, mixed>|null
     */
    public function upload(string $localPath, string $disk, string $remotePrefix): ?array
    {
        if ($disk === '' || $disk === '0') {
            return null;
        }

        if (! is_file($localPath) && ! is_dir($localPath)) {
            return null;
        }

        $filesystem = Storage::disk($disk);
        $timestamp = now()->format('Ymd_His');

        if (is_dir($localPath)) {
            return $this->uploadDirectory($filesystem, $localPath, $remotePrefix.'/'.$timestamp);
        }

        $remotePath = $remotePrefix.'/'.$timestamp.'_'.basename($localPath);

        $stream = fopen($localPath, 'r');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot open backup artifact [%s] for streaming.', $localPath));
        }

        try {
            $filesystem->writeStream($remotePath, $stream);
        } finally {
            fclose($stream);
        }

        File::delete($localPath);

        return [
            'disk' => $disk,
            'path' => $remotePath,
            'url' => $filesystem->url($remotePath),
            'size' => filesize($localPath) ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadDirectory(Filesystem $filesystem, string $localDir, string $remotePath): array
    {
        $totalSize = 0;

        foreach (File::allFiles($localDir) as $file) {
            $relativePath = $file->getRelativePathname();
            $stream = fopen($file->getRealPath(), 'r');

            if ($stream === false) {
                continue;
            }

            try {
                $filesystem->writeStream($remotePath.'/'.$relativePath, $stream);
            } finally {
                fclose($stream);
            }

            $totalSize += (int) $file->getSize();
        }

        File::deleteDirectory($localDir);

        return [
            'disk' => $disk,
            'path' => $remotePath,
            'url' => $filesystem->url($remotePath),
            'size' => $totalSize,
        ];
    }
}
