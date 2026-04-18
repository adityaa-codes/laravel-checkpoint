<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console\Concerns;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\table;

trait UsesLaravelPrompts
{
    protected function enhancedInteractiveMode(): bool
    {
        return $this->input !== null && $this->input->isInteractive() && ! app()->runningUnitTests();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    protected function promptTable(array $headers, array $rows): void
    {
        if (app()->runningUnitTests()) {
            $this->table($headers, $rows);

            return;
        }

        table(headers: $headers, rows: $rows);
    }

    protected function promptInfo(string $message): void
    {
        if (app()->runningUnitTests()) {
            $this->info($message);

            return;
        }

        info($message);
    }

    protected function promptWarning(string $message): void
    {
        if (app()->runningUnitTests()) {
            $this->warn($message);

            return;
        }

        warning($message);
    }

    protected function promptError(string $message): void
    {
        if (app()->runningUnitTests()) {
            $this->error($message);

            return;
        }

        error($message);
    }
}
