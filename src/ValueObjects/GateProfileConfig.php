<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

/** @internal */
final readonly class GateProfileConfig
{
    public function __construct(
        public string $environment = 'production',
        public ?string $overrideProfile = null,
        public string $defaultProfile = 'production',
        /** @var array<string, string> */
        public array $environmentProfileMap = [],
        /** @var array<string, int> */
        public array $codeMap = [],
        /** @var array<string, array<string, mixed>> */
        public array $profiles = [],
    ) {}
}
