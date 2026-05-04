<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class BackupArtifactUploader
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function upload(string $localPath): array
    {
        $disks = (array) config('checkpoint.destination.disks', []);

        if ($disks === []) {
            return [];
        }

        if (! is_file($localPath) && ! is_dir($localPath)) {
            return [];
        }

        $encryption = (bool) config('checkpoint.encryption.enabled', false);
        $timestamp = now()->format('Ymd_His');
        $results = [];

        foreach ($disks as $disk) {
            $filesystem = Storage::disk($disk);

            if (is_dir($localPath)) {
                $results[] = $this->uploadDirectory($filesystem, $localPath, $disk, 'checkpoint-basebackups/'.$timestamp, $encryption);
            } else {
                $remotePath = 'checkpoint-exports/'.$timestamp.'_'.basename($localPath);
                $results[] = $this->uploadFile($filesystem, $localPath, $disk, $remotePath, $encryption);
            }
        }

        File::delete(is_dir($localPath) ? $localPath : $localPath);
        is_file($localPath) && File::delete($localPath);
        is_dir($localPath) && File::deleteDirectory($localPath);

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFile($filesystem, string $localPath, string $disk, string $remotePath, bool $encrypt): array
    {
        $stream = fopen($localPath, 'r');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot open backup artifact [%s] for streaming.', $localPath));
        }

        try {
            if ($encrypt) {
                $encryptedStream = $this->encryptStream($stream);
                $filesystem->writeStream($remotePath.'.enc', $encryptedStream);
                fclose($encryptedStream);
            } else {
                $filesystem->writeStream($remotePath, $stream);
            }
        } finally {
            fclose($stream);
        }

        $finalPath = $encrypt ? $remotePath.'.enc' : $remotePath;

        return [
            'disk' => $disk,
            'path' => $finalPath,
            'encrypted' => $encrypt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadDirectory($filesystem, string $localDir, string $disk, string $remotePath, bool $encrypt): array
    {
        $files = File::allFiles($localDir);

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $stream = fopen($file->getRealPath(), 'r');

            if ($stream === false) {
                continue;
            }

            try {
                $targetPath = $remotePath.'/'.$relativePath;

                if ($encrypt) {
                    $encryptedStream = $this->encryptStream($stream);
                    $filesystem->writeStream($targetPath.'.enc', $encryptedStream);
                    fclose($encryptedStream);
                } else {
                    $filesystem->writeStream($targetPath, $stream);
                }
            } finally {
                fclose($stream);
            }
        }

        return [
            'disk' => $disk,
            'path' => $remotePath,
            'encrypted' => $encrypt,
            'files' => count($files),
        ];
    }

    /**
     * @param  resource  $stream
     * @return resource
     */
    private function encryptStream($stream)
    {
        $key = $this->encryptionKey();
        $iv = random_bytes(16);
        $encrypted = fopen('php://temp', 'w+b');

        fwrite($encrypted, $iv);

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false) {
                break;
            }

            fwrite($encrypted, openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
        }

        rewind($encrypted);

        return $encrypted;
    }

    private function encryptionKey(): string
    {
        $appKey = (string) config('app.key', '');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY must be set to enable backup encryption.');
        }

        $key = substr($appKey, 0, 7) === 'base64:'
            ? base64_decode(substr($appKey, 7))
            : $appKey;

        return hash('sha256', $key, true);
    }
}
