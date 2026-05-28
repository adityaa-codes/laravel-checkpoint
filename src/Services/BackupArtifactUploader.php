<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class BackupArtifactUploader
{
    private const MAGIC = "CPENC\0\0\0";

    private const CHUNK_SIZE = 8192;

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

        if (! File::isFile($localPath) && ! File::isDirectory($localPath)) {
            return [];
        }

        $encrypt = $this->config->get('checkpoint.encryption.enabled', false);
        $timestamp = now()->format('Ymd_His');
        $results = [];

        foreach ($disks as $disk) {
            $filesystem = $this->filesystemFactory->disk($disk);

            if (File::isDirectory($localPath)) {
                $results[] = $this->uploadDirectory($filesystem, $localPath, $disk, 'checkpoint-basebackups/'.$timestamp, $encrypt);
            } else {
                $remotePath = 'checkpoint-exports/'.$timestamp.'_'.File::basename($localPath);
                $results[] = $this->uploadFile($filesystem, $localPath, $disk, $remotePath, $encrypt);
            }
        }

        if (File::isFile($localPath)) {
            $this->files->delete($localPath);
        }
        if (File::isDirectory($localPath)) {
            $this->files->deleteDirectory($localPath);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadFile(\Illuminate\Contracts\Filesystem\Filesystem $filesystem, string $localPath, string $disk, string $remotePath, bool $encrypt, bool $decrypt = false): array
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
        } elseif ($decrypt) {
            $decryptedStream = $this->decryptStream($stream);
            $filesystem->writeStream($finalPath, $decryptedStream);
            fclose($decryptedStream);
        } else {
            $filesystem->writeStream($finalPath, $stream);
        }

        fclose($stream);

        return ['disk' => $disk, 'path' => $finalPath, 'encrypted' => $encrypt, 'decrypted' => $decrypt];
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
        $key = $this->deriveEncryptionKey();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $encrypted = fopen('php://temp', 'w+b');
        fwrite($encrypted, self::MAGIC);
        fwrite($encrypted, $header);

        $nextChunk = fread($stream, self::CHUNK_SIZE);

        while ($nextChunk !== false && $nextChunk !== '') {
            $chunk = $nextChunk;
            $nextChunk = fread($stream, self::CHUNK_SIZE);

            $tag = ($nextChunk === false || $nextChunk === '')
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            $len = pack('N', strlen($ciphertext));
            fwrite($encrypted, $len);
            fwrite($encrypted, $ciphertext);
        }

        rewind($encrypted);

        return $encrypted;
    }

    /**
     * @param  resource  $stream
     * @return resource
     */
    private function decryptStream($stream)
    {
        $key = $this->deriveEncryptionKey();

        $magic = fread($stream, 8);

        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Stream is not a valid Checkpoint encrypted artifact.');
        }

        $header = fread($stream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (! is_string($header)) {
            throw new RuntimeException('Failed to read encryption header from stream.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

        $decrypted = fopen('php://temp', 'w+b');

        while (! feof($stream)) {
            $lenBytes = fread($stream, 4);

            if ($lenBytes === false || strlen($lenBytes) < 4) {
                break;
            }

            $unpacked = unpack('N', $lenBytes);

            if ($unpacked === false) {
                break;
            }

            $len = $unpacked[1];
            $ciphertext = fread($stream, $len);

            if ($ciphertext === false) {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);

            if ($result === false) {
                throw new RuntimeException('Decryption failed: corrupted or tampered stream.');
            }

            [$chunk, $tag] = $result;

            fwrite($decrypted, $chunk);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        rewind($decrypted);

        return $decrypted;
    }

    private function deriveEncryptionKey(): string
    {
        $appKey = $this->config->get('app.key', '');

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY must be set to enable backup encryption.');
        }

        $rawKey = Str::startsWith((string) $appKey, 'base64:')
            ? Str::fromBase64(Str::substr((string) $appKey, 7))
            : $appKey;

        return hash_hkdf('sha256', (string) $rawKey, 32, 'checkpoint-encryption-v2', '');
    }
}
