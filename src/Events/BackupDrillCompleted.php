<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;

readonly class BackupDrillCompleted
{
    public function __construct(public BackupDrillRun $run) {}
}
