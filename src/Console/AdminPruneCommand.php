<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class AdminPruneCommand extends Command
{
    protected $signature = 'db-ops:admin:prune';

    protected $description = 'Journey command: prune aged operational records.';

    public function handle(): int
    {
        return $this->call('db-ops:prune');
    }
}

