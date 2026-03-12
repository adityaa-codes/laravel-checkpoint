<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Process\Process;

/** @internal */
final readonly class CommandOutputCapture
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{output:string,metadata:array<string,mixed>}
     */
    public function capture(string $output, ?int $maxBytes = null): array
    {
        $maxBytes ??= $this->maxPersistedBytes();
        $originalBytes = strlen($output);

        return $this->finalizeCapture(
            $this->cut($output, 0, $maxBytes),
            $this->tail($output, $maxBytes),
            $originalBytes,
            $maxBytes,
        );
    }

    /**
     * @return array{output:string,metadata:array<string,mixed>}
     */
    public function captureProcess(Process $process, ?callable $tap = null): array
    {
        $maxBytes = $this->maxPersistedBytes();
        $prefix = '';
        $suffix = '';
        $originalBytes = 0;

        $process->run(function (string $type, string $chunk) use (&$prefix, &$suffix, &$originalBytes, $maxBytes, $tap): void {
            if ($chunk === '') {
                return;
            }

            if ($tap !== null) {
                $tap($chunk, $type);
            }
            $originalBytes += strlen($chunk);

            if (strlen($prefix) < $maxBytes) {
                $prefix .= $this->cut($chunk, 0, $maxBytes - strlen($prefix));
            }

            $suffix = $this->tail($suffix.$chunk, $maxBytes);
        });

        return $this->finalizeCapture($prefix, $suffix, $originalBytes, $maxBytes);
    }

    public function maxPersistedBytes(): int
    {
        $maxBytes = (int) $this->config->get('checkpoint.output.max_persisted_bytes', 65536);

        if ($maxBytes < 1) {
            throw new ConfigurationException('checkpoint.output.max_persisted_bytes must be greater than zero.');
        }

        return $maxBytes;
    }

    /**
     * @return array{output:string,metadata:array<string,mixed>}
     */
    private function finalizeCapture(string $prefix, string $suffix, int $originalBytes, int $maxBytes): array
    {
        if ($originalBytes <= $maxBytes) {
            return [
                'output' => $this->cut($prefix, 0, $originalBytes),
                'metadata' => [
                    'output_capture' => [
                        'truncated' => false,
                        'original_bytes' => $originalBytes,
                        'persisted_bytes' => $originalBytes,
                    ],
                ],
            ];
        }

        $marker = sprintf("\n...[truncated %d bytes]...\n", $originalBytes - $maxBytes);
        $markerBytes = strlen($marker);

        if ($markerBytes >= $maxBytes) {
            $persistedOutput = substr($marker, 0, $maxBytes);
        } else {
            $headBytes = (int) floor(($maxBytes - $markerBytes) / 2);
            $tailBytes = $maxBytes - $markerBytes - $headBytes;
            $persistedOutput = $this->cut($prefix, 0, $headBytes)
                .$marker
                .$this->tail($suffix, $tailBytes);
        }

        return [
            'output' => $persistedOutput,
            'metadata' => [
                'output_capture' => [
                    'truncated' => true,
                    'original_bytes' => $originalBytes,
                    'persisted_bytes' => strlen($persistedOutput),
                    'max_persisted_bytes' => $maxBytes,
                ],
            ],
        ];
    }

    private function tail(string $value, int $maxBytes): string
    {
        if ($maxBytes < 1 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return $this->cut($value, -$maxBytes);
    }

    private function cut(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
