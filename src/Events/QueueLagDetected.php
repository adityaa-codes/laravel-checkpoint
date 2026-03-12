<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

final readonly class QueueLagDetected
{
    public function __construct(
        public string $queue,
        public int $staleRunCount,
        public int $thresholdMinutes,
        public int $oldestStaleAgeMinutes,
        /** @var array<int, int> */
        public array $staleRunIds,
        public bool $staleRunIdsTruncated,
        public int $version = 1,
    ) {}
}
