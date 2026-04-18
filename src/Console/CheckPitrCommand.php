<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class CheckPitrCommand extends Command
{
    protected $signature = 'db-ops:check:pitr {target? : PITR target datetime (defaults to now).} {--format=table : Output format: table or json.} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Journey command: run PITR readiness checks.';

    public function handle(): int
    {
        $parameters = array_filter([
            'target' => $this->argument('target'),
            '--format' => $this->option('format'),
            '--agent' => (bool) $this->option('agent') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('db-ops:pitr-readiness', $parameters);
    }
}

