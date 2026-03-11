<?php

namespace AdityaaCodes\LaravelCheckpoint\Commands;

use Illuminate\Console\Command;

class LaravelCheckpointCommand extends Command
{
    public $signature = 'laravel-checkpoint';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
