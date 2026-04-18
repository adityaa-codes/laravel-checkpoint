<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupIncrCommand extends Command
{
    protected $signature = 'db-ops:do:backup:incr';

    protected $description = 'Journey command: queue pgBackRest incremental backup.';

    public function handle(): int
    {
        return $this->call('db-ops:enqueue', ['operation' => 'pgbackrest_backup_incr']);
    }
}

