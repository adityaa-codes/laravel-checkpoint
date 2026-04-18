<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoRestoreFileCommand extends Command
{
    protected $signature = 'db-ops:do:restore:file {file : Backup file path or label to restore}';

    protected $description = 'Journey command: restore a specific logical backup file.';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_string($file) || $file === '') {
            $this->components->error('A backup file path or label is required.');

            return self::INVALID;
        }

        return $this->call('db-ops:enqueue', [
            'operation' => 'logical_restore_file',
            '--argument' => $file,
        ]);
    }
}
