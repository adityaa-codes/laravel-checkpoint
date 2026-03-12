<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/**
 * @api
 */
final readonly class BackupFreshnessAlarmTriggered
{
    public function __construct(
        public ?CommandRun $run,
        public string $reason,
        public ?int $ageHours,
        public int $thresholdHours,
        public int $version = 1,
    ) {}
}
