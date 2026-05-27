<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Events;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final readonly class PostgresPhysicalBackupCompleted
{
    public function __construct(
        public readonly CommandRun $run,
        public readonly string $artifactPath,
        public readonly string $walMethod,
        public readonly string $compression,
        public readonly int $exitCode,
    ) {}
}
