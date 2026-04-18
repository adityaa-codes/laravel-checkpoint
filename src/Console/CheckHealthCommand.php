<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class CheckHealthCommand extends Command
{
    protected $signature = 'db-ops:check:health';

    protected $description = 'Journey command: run stale-running health sweep.';

    public function handle(): int
    {
        return $this->call('db-ops:health-check');
    }
}

