<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class BackupArtifactUploader
{
    public function __construct(
        private Repository $config,
        private FilesystemFactory $filesystemFactory,
        private Filesystem $files,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upload(string $localPath): array
    {
        $disks = $this->config->get('checkpoint.destination.disks', []);

        if ($disks === []) {
            return [];
        }

        if (! is_file($localPath) && ! is_dir($localPath)) {
            return [];
        }

        $encrypt = $this->config->get('checkpoint.encryption.enabled', false);
        $timestamp = now()->format('Ymd_His');
        $results = [];

        foreach ($disks as $disk) {
            $filesystem = $this->filesystemFactory->disk($disk);

            if (is_dir($localPath)) {
                $results[] = $this->uploadDirectory($filesystem, $localPath, $disk, 'checkpoint-basebackups/'.$timestamp, $encrypt);
            } else {
                $remotePath = 'checkpoint-exports/'.$timestamp.'_'.basename($localPath);
                $results[] = $this->uploadFile($filesystem, $localPath, $disk, $remotePath, $encrypt);
            }
        }

        is_file($localPath) && $this->files->delete($localPath);
        is_dir($localPath) && $this->files->deleteDirectory($localPath);

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFile(\Illuminate\Contracts\Filesystem\Filesystem $filesystem, string $localPath, string $disk, string $remotePath, bool $encrypt): array
    {
        $stream = fopen($localPath, 'r');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Cannot open backup artifact [%s] for streaming.', $localPath));
        }

        $finalPath = $encrypt ? $remotePath.'.enc' : $remotePath;

        if ($encrypt) {
            $encryptedStream = $this->encryptStream($stream);
            $filesystem->writeStream($finalPath, $encryptedStream);
            fclose($encryptedStream);
        } else {
            $filesystem->writeStream($finalPath, $stream);
        }

        fclose($stream);

        return ['disk' => $disk, 'path' => $finalPath, 'encrypted' => $encrypt];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadDirectory(\Illuminate\Contracts\Filesystem\Filesystem $filesystem, string $localDir, string $disk, string $remotePath, bool $encrypt): array
    {
        $allFiles = $this->files->allFiles($localDir);
        $count = 0;

        foreach ($allFiles as $file) {
            $stream = fopen($file->getRealPath(), 'r');

            if ($stream === false) {
                continue;
            }

            $targetPath = $remotePath.'/'.$file->getRelativePathname();

            if ($encrypt) {
                $encryptedStream = $this->encryptStream($stream);
                $filesystem->writeStream($targetPath.'.enc', $encryptedStream);
                fclose($encryptedStream);
            } else {
                $filesystem->writeStream($targetPath, $stream);
            }

            fclose($stream);
            $count++;
        }

        return ['disk' => $disk, 'path' => $remotePath, 'encrypted' => $encrypt, 'files' => $count];
    }

    /**
     * @param  resource  $stream
     * @return resource
     */
    private function encryptStream($stream)
    {
        $appKey = $this->config->get('app.key', '');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY must be set to enable backup encryption.');
        }

        $key = substr($appKey, 0, 7) === 'base64:'
            ? base64_decode(substr($appKey, 7))
            : $appKey;

        $encryptionKey = hash('sha256', $key, true);
        $iv = random_bytes(16);
        $encrypted = fopen('php://temp', 'w+b');

        fwrite($encrypted, $iv);

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false) {
                break;
            }

            fwrite($encrypted, openssl_encrypt($chunk, 'aes-256-cbc', $encryptionKey, OPENSSL_RAW_DATA, $iv));
        }

        rewind($encrypted);

        return $encrypted;
    }
}
