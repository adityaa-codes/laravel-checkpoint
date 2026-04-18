<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class CheckReportCommand extends Command
{
    protected $signature = 'db-ops:check:report {--limit=10 : Number of recent runs to include.} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Journey command: run consolidated operational report checks.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--limit' => $this->option('limit'),
            '--format' => $this->option('format'),
            '--agent' => (bool) $this->option('agent') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('db-ops:report', $parameters);
    }
}
