<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/** @internal */
final readonly class CommandOutputStore
{
    public function __construct(
        private Repository $config,
        private FilesystemFactory $filesystems,
        private CommandOutputCapture $capture,
    ) {}

    /**
     * @return array{command_output:string|null,metadata:array<string,mixed>}
     */
    public function persist(CommandRun $run, string $output): array
    {
        if ($this->storageMode() === 'database') {
            return [
                'command_output' => $output,
                'metadata' => [
                    'output_storage' => [
                        'driver' => 'database',
                        'externalized' => false,
                        'stored_bytes' => strlen($output),
                    ],
                ],
            ];
        }

        $disk = $this->filesystemDisk();
        $path = $this->pathFor($run);
        $written = $this->filesystems->disk($disk)->put($path, $output);

        if ($written !== true) {
            throw new ConfigurationException(sprintf('Unable to persist command output to [%s:%s].', $disk, $path));
        }

        $inlineBytes = $this->inlineBytes();
        $inlineOutput = $inlineBytes > 0 ? $this->capture->capture($output, $inlineBytes)['output'] : null;

        return [
            'command_output' => $inlineOutput,
            'metadata' => [
                'output_storage' => [
                    'driver' => 'filesystem',
                    'externalized' => true,
                    'disk' => $disk,
                    'path' => $path,
                    'stored_bytes' => strlen($output),
                    'inline_bytes' => strlen((string) $inlineOutput),
                ],
            ],
        ];
    }

    /**
     * @return array{disk:string,path:string,temp_path:string}|null
     */
    public function startCapture(CommandRun $run): ?array
    {
        if ($this->storageMode() !== 'filesystem') {
            return null;
        }

        $tempPath = tempnam($this->tempDir(), 'checkpoint-output-');

        if ($tempPath === false) {
            throw new ConfigurationException('Unable to allocate temporary command output storage.');
        }

        return [
            'disk' => $this->filesystemDisk(),
            'path' => $this->pathFor($run),
            'temp_path' => $tempPath,
        ];
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}|null  $session
     */
    public function appendCaptureChunk(?array $session, string $chunk): void
    {
        if ($session === null || $chunk === '') {
            return;
        }

        try {
            $written = file_put_contents($session['temp_path'], $chunk, FILE_APPEND);
        } catch (\ErrorException) {
            $written = false;
        }

        if ($written === false) {
            logger()->error('Unable to append command output to temporary storage.', ['temp_path' => $session['temp_path']]);

            throw new ConfigurationException('Unable to append command output to temporary storage.');
        }
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}|null  $session
     */
    public function discardCaptureSession(?array $session): void
    {
        if ($session === null) {
            return;
        }

        $this->discardCapture($session);
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}|null  $session
     * @return array{command_output:string|null,metadata:array<string,mixed>}
     */
    public function finishCapture(CommandRun $run, string $capturedOutput, ?array $session): array
    {
        if ($session === null) {
            return $this->persist($run, $capturedOutput);
        }

        try {
            $stream = fopen($session['temp_path'], 'rb');
        } catch (\ErrorException) {
            $stream = false;
        }

        if ($stream === false) {
            $this->discardCapture($session);
            logger()->error('Unable to open temporary command output storage.', ['temp_path' => $session['temp_path']]);

            throw new ConfigurationException('Unable to open temporary command output storage.');
        }

        try {
            $written = $this->filesystems->disk($session['disk'])->put($session['path'], $stream);

            if ($written !== true) {
                throw new ConfigurationException(sprintf('Unable to persist command output to [%s:%s].', $session['disk'], $session['path']));
            }

            $inlineBytes = $this->inlineBytes();
            $inlineOutput = $inlineBytes > 0 ? $this->preview($capturedOutput, $inlineBytes) : null;
            $storedBytes = filesize($session['temp_path']);

            return [
                'command_output' => $inlineOutput,
                'metadata' => [
                    'output_storage' => [
                        'driver' => 'filesystem',
                        'externalized' => true,
                        'disk' => $session['disk'],
                        'path' => $session['path'],
                        'stored_bytes' => $storedBytes === false ? null : $storedBytes,
                        'inline_bytes' => strlen((string) $inlineOutput),
                    ],
                ],
            ];
        } finally {
            fclose($stream);
            $this->discardCapture($session);
        }
    }

    public function cleanup(CommandRun $run): void
    {
        $storage = $this->storageMetadata($run);

        if (is_array($storage)) {
            $this->cleanupMetadata($storage);
        }
    }

    /**
     * @param  array<string, mixed>  $storage
     */
    public function cleanupMetadata(array $storage): void
    {
        if (($storage['externalized'] ?? false) !== true) {
            return;
        }

        $disk = $storage['disk'] ?? null;
        $path = $storage['path'] ?? null;

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

        $this->filesystems->disk($disk)->delete($path);
    }

    public function resolve(CommandRun $run): ?string
    {
        $storage = $this->storageMetadata($run);

        if (! is_array($storage) || ($storage['externalized'] ?? false) !== true) {
            return $run->command_output;
        }

        $disk = $storage['disk'] ?? null;
        $path = $storage['path'] ?? null;

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return $run->command_output;
        }

        $filesystem = $this->filesystems->disk($disk);

        if (! $filesystem->exists($path)) {
            return $run->command_output;
        }

        return $filesystem->get($path);
    }

    private function storageMode(): string
    {
        return $this->config->get('checkpoint.output.storage', 'database');
    }

    private function filesystemDisk(): string
    {
        return $this->config->get('checkpoint.output.filesystem.disk', 'local');
    }

    private function pathPrefix(): string
    {
        return str($this->config->get('checkpoint.output.filesystem.path_prefix', 'checkpoint/command-output'))->trim('/')->value();
    }

    private function inlineBytes(): int
    {
        return max(0, $this->config->get('checkpoint.output.filesystem.inline_bytes', 2048));
    }

    private function tempDir(): string
    {
        $configured = str($this->config->get('checkpoint.temp_dir', storage_path('app/checkpoint/tmp')))->trim()->value();

        if ($configured === '') {
            throw new ConfigurationException('checkpoint.temp_dir must be a non-empty string.');
        }

        if (file_exists($configured) && ! is_dir($configured)) {
            throw new ConfigurationException(sprintf('Unable to create checkpoint temp directory [%s].', $configured));
        }

        if (! is_dir($configured)) {
            try {
                $created = mkdir($configured, 0700, true);
            } catch (\ErrorException) {
                $created = false;
            }

            if (! $created && ! is_dir($configured)) {
                logger()->error('Unable to create checkpoint temp directory.', ['path' => $configured]);

                throw new ConfigurationException(sprintf('Unable to create checkpoint temp directory [%s].', $configured));
            }
        }

        return $configured;
    }

    private function pathFor(CommandRun $run): string
    {
        $prefix = $this->pathPrefix();
        $fileName = sprintf('command-run-%d.log', (int) $run->getKey());

        return $prefix === '' ? $fileName : $prefix.'/'.$fileName;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storageMetadata(CommandRun $run): ?array
    {
        $metadata = $run->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        return is_array($metadata['output_storage'] ?? null) ? $metadata['output_storage'] : null;
    }

    private function preview(string $value, int $maxBytes): string
    {
        if ($maxBytes < 1 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $maxBytes, 'UTF-8')
            : substr($value, 0, $maxBytes);
    }

    /**
     * @param  array{disk:string,path:string,temp_path:string}  $session
     */
    private function discardCapture(array $session): void
    {
        if (is_file($session['temp_path'])) {
            try {
                $removed = unlink($session['temp_path']);
            } catch (\ErrorException) {
                $removed = false;
            }

            if ($removed === false) {
                logger()->error('Unable to remove temporary command output file.', ['temp_path' => $session['temp_path']]);
            }
        }
    }
}
