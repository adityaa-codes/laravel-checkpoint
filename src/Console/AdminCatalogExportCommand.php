<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class AdminCatalogExportCommand extends Command
{
    protected $signature = 'db-ops:admin:catalog-export {--output= : Destination file path for exported JSON.}';

    protected $description = 'Journey command: export restore catalog snapshot.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--output' => $this->option('output'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->call('db-ops:catalog-export', $parameters);
    }
}

