<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoBackupDiffCommand extends Command
{
    protected $signature = 'checkpoint:do:backup:diff';

    protected $description = 'Journey command: queue pgBackRest differential backup.';

    public function handle(): int
    {
        return $this->call('checkpoint:enqueue', ['operation' => 'pgbackrest_backup_diff']);
    }
}
