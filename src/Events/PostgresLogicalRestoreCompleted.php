<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final readonly class PostgresLogicalRestoreCompleted
{
    public function __construct(
        public readonly CommandRun $run,
        public readonly string $restoreTarget,
        public readonly string $format,
        public readonly int $exitCode,
    ) {}
}
