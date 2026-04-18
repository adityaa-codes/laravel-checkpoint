<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoRestoreLatestCommand extends Command
{
    protected $signature = 'db-ops:do:restore:latest';

    protected $description = 'Journey command: restore from latest logical backup.';

    public function handle(): int
    {
        return $this->call('db-ops:enqueue', ['operation' => 'logical_restore_latest']);
    }
}

