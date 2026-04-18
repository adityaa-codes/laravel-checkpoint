<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class AdminRetentionCommand extends Command
{
    protected $signature = 'db-ops:admin:retention
        {--format=table : Output format: table or json.}
        {--limit=100 : Maximum number of candidate rows to evaluate.}
        {--dry-run : Preview retention decisions without deleting records.}
        {--apply : Apply retention decisions immediately.}';

    protected $description = 'Journey command: retention preview/apply for governance workflows.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--format' => $this->option('format'),
            '--limit' => $this->option('limit'),
            '--dry-run' => (bool) $this->option('dry-run') ? true : null,
            '--apply' => (bool) $this->option('apply') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('db-ops:retention-policy', $parameters);
    }
}
