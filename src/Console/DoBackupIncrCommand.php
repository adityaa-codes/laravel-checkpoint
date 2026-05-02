<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupIncrCommand extends Command
{
    protected $signature = 'checkpoint:do:backup:incr';

    protected $description = 'Journey command: queue pgBackRest incremental backup.';

    public function handle(): int
    {
        return $this->call('checkpoint:enqueue', ['operation' => 'pgbackrest_backup_incr']);
    }
}
