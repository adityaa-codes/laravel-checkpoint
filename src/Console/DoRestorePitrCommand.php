<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoRestorePitrCommand extends Command
{
    protected $signature = 'db-ops:do:restore:pitr {target : PITR restore target timestamp}';

    protected $description = 'Journey command: run point-in-time restore operation.';

    public function handle(): int
    {
        $target = $this->argument('target');

        if (! is_string($target) || $target === '') {
            $this->components->error('A PITR target timestamp is required.');

            return self::INVALID;
        }

        return $this->call('db-ops:enqueue', [
            'operation' => 'pitr_restore',
            '--argument' => $target,
        ]);
    }
}
