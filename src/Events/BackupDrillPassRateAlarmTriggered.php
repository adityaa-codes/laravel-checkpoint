<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;

/**
 * @api
 */
final readonly class BackupDrillPassRateAlarmTriggered
{
    public function __construct(
        public int $windowDays,
        public int $passing,
        public int $total,
        public float $passRatePercent,
        public float $thresholdPercent,
        public ?BackupDrillRun $latestRun,
        public int $version = 1,
    ) {}
}
