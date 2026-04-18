<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoReplicateCommand extends Command
{
    protected $signature = 'db-ops:do:replicate
        {source? : Source endpoint (profile:<id>, DSN, or key=value pairs)}
        {destination? : Destination endpoint (profile:<id>, DSN, or key=value pairs)}
        {--source= : Source endpoint override}
        {--destination= : Destination endpoint override}
        {--apply : Queue apply mode. Without this flag, replication runs in dry-run mode.}
        {--force-overwrite : Request overwrite behavior for apply mode.}
        {--critical-table=* : Critical table names to guard overwrite. Repeat option for multiple tables.}';

    protected $description = 'Journey command: run replication workflow.';

    public function handle(): int
    {
        $parameters = array_filter([
            'source' => $this->argument('source'),
            'destination' => $this->argument('destination'),
            '--source' => $this->option('source'),
            '--destination' => $this->option('destination'),
            '--apply' => (bool) $this->option('apply') ? true : null,
            '--force-overwrite' => (bool) $this->option('force-overwrite') ? true : null,
            '--critical-table' => $this->option('critical-table'),
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('db-ops:replicate', $parameters);
    }
}

