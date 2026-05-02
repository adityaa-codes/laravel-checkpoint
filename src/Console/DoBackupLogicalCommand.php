<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupLogicalCommand extends Command
{
    protected $signature = 'checkpoint:do:backup:logical';

    protected $description = 'Journey command: queue logical backup.';

    public function handle(): int
    {
        return $this->call('checkpoint:enqueue', ['operation' => 'logical_backup']);
    }
}
