<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

final readonly class QueueLagDetected
{
    public function __construct(
        public string $queue,
        public int $staleRunCount,
        public int $thresholdMinutes,
    ) {}
}
