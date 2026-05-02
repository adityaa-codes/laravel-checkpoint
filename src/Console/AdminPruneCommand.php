<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class AdminPruneCommand extends Command
{
    protected $signature = 'checkpoint:admin:prune';

    protected $description = 'Journey command: prune aged operational records.';

    public function handle(): int
    {
        return $this->call('checkpoint:prune');
    }
}
