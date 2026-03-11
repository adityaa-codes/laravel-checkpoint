<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Throwable;

readonly class BackupFailed
{
    public function __construct(
        public CommandRun $run,
        public int $exitCode,
        public string $output,
        public ?Throwable $exception = null,
    ) {}
}
