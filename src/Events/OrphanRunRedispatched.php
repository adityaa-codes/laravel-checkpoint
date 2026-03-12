<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final readonly class OrphanRunRedispatched
{
    public function __construct(
        public CommandRun $run,
        public string $queue,
        public int $thresholdMinutes,
        public int $staleAgeMinutes,
        public int $version = 1,
    ) {}
}
