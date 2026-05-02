<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class DoStatusCommand extends Command
{
    protected $signature = 'checkpoint:do:status
        {--limit=10}
        {--summary : Show an operator-facing summary instead of recent runs.}
        {--format=table : Output format: table or json.}
        {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Journey command: inspect run status and health summary.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--limit' => $this->option('limit'),
            '--summary' => (bool) $this->option('summary') ? true : null,
            '--format' => $this->option('format'),
            '--agent' => (bool) $this->option('agent') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('checkpoint:status', $parameters);
    }
}
