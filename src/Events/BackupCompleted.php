<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

readonly class BackupCompleted
{
    public function __construct(
        public CommandRun $run,
        public int $exitCode,
        public string $output,
    ) {}
}
