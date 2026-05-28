<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

/** @internal */
final class DriverContext
{
    public ?DriverResult $result = null;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $operation,
        public readonly ?string $argument,
        public readonly string $driverName,
        public readonly array $metadata,
        public readonly string $runUuid,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function markSuccess(string $output = '', int $exitCode = 0, array $metadata = []): void
    {
        $this->result = new DriverResult($exitCode, $output, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public function markFailure(string $output = '', int $exitCode = 1, array $metadata = []): void
    {
        $this->result = new DriverResult($exitCode, $output, $metadata);
    }

    public function isSuccessful(): bool
    {
        return $this->result instanceof DriverResult && $this->result->isSuccessful();
    }
}
