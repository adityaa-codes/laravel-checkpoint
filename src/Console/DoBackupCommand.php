<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupCommand extends Command
{
    protected $signature = 'db-ops:do:backup';

    protected $description = 'Journey command: queue a standard logical backup.';

    public function handle(): int
    {
        return $this->call('db-ops:enqueue-backup');
    }
}
