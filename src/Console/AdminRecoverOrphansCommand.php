<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class AdminRecoverOrphansCommand extends Command
{
    protected $signature = 'checkpoint:admin:recover-orphans {--batch=500 : Maximum stale runs to mark per execution.}';

    protected $description = 'Journey command: mark stale queued/running jobs as failed.';

    public function handle(): int
    {
        return $this->call('checkpoint:recover-orphans', [
            '--batch' => (int) $this->option('batch'),
        ]);
    }
}
