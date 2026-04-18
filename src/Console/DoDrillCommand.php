<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoDrillCommand extends Command
{
    protected $signature = 'db-ops:do:drill';

    protected $description = 'Journey command: queue a backup drill.';

    public function handle(): int
    {
        return $this->call('db-ops:enqueue-drill');
    }
}

