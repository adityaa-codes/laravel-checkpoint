<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console\Concerns;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

trait UsesLaravelPrompts
{
    protected function enhancedInteractiveMode(): bool
    {
        return $this->input !== null && $this->input->isInteractive() && ! $this->runningUnitTests();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    protected function promptTable(array $headers, array $rows): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->table($headers, $rows);

            return;
        }

        $this->interactiveTable($headers, $rows);
    }

    protected function promptInfo(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->info($message);

            return;
        }

        $this->interactiveInfo($message);
    }

    protected function promptWarning(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->warn($message);

            return;
        }

        $this->interactiveWarning($message);
    }

    protected function promptError(string $message): void
    {
        if (! $this->enhancedInteractiveMode()) {
            $this->error($message);

            return;
        }

        $this->interactiveError($message);
    }

    protected function runningUnitTests(): bool
    {
        return app()->runningUnitTests();
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int, mixed>>  $rows
     */
    protected function interactiveTable(array $headers, array $rows): void
    {
        table(headers: $headers, rows: $rows);
    }

    protected function interactiveInfo(string $message): void
    {
        info($message);
    }

    protected function interactiveWarning(string $message): void
    {
        warning($message);
    }

    protected function interactiveError(string $message): void
    {
        error($message);
    }
}
