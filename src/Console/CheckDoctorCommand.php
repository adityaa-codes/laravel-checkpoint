<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Console\Command;

final class CheckDoctorCommand extends Command
{
    protected $signature = 'checkpoint:check:doctor {--format=table} {--agent : Emit compact AI-agent friendly JSON output.}';

    protected $description = 'Journey command: run checkpoint health diagnostics.';

    public function handle(): int
    {
        $parameters = array_filter([
            '--format' => $this->option('format'),
            '--agent' => (bool) $this->option('agent') ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->call('checkpoint:doctor', $parameters);
    }
}
