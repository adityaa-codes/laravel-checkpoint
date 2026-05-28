<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

final readonly class GateDecision
{
    public function __construct(
        public string $profile,
        public string $profileSource,
        public string $verdict,
        public string $failedGate,
        public int $exitCode,
    ) {}
}
