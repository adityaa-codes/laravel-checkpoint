<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupFullCommand extends Command
{
    protected $signature = 'checkpoint:do:backup:full';

    protected $description = 'Journey command: queue pgBackRest full backup.';

    public function handle(): int
    {
        return $this->call('checkpoint:enqueue', ['operation' => 'pgbackrest_backup_full']);
    }
}
