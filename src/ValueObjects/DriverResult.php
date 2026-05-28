<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

/** @internal */
final readonly class DriverResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function success(string $output = '', int $exitCode = 0, array $metadata = []): self
    {
        return new self($exitCode, $output, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function failure(string $output = '', int $exitCode = 1, array $metadata = []): self
    {
        return new self($exitCode, $output, $metadata);
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
