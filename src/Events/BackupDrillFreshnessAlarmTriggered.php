<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;

/**
 * @api
 */
final readonly class BackupDrillFreshnessAlarmTriggered
{
    public function __construct(
        public ?BackupDrillRun $run,
        public string $reason,
        public ?int $ageDays,
        public int $thresholdDays,
        public int $version = 1,
    ) {}
}
