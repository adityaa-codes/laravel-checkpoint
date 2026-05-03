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

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    protected function policyProfileOverride(): ?string
    {
        $override = $this->stringOption('policy-profile');

        if (! is_string($override)) {
            return null;
        }

        $override = trim($override);

        return $override !== '' ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $gateDecision
     * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
     */
    protected function machineGateDecision(array $gateDecision): array
    {
        return [
            'profile' => (string) ($gateDecision['profile'] ?? 'unknown'),
            'profile_source' => (string) ($gateDecision['profile_source'] ?? 'default'),
            'verdict' => (string) ($gateDecision['verdict'] ?? 'fail'),
            'failed_gate' => (string) ($gateDecision['failed_gate'] ?? 'policy'),
            'exit_code' => (int) ($gateDecision['exit_code'] ?? 12),
        ];
    }

    protected function shouldCollapsePassingChecks(): bool
    {
        return ! $this->getOutput()->isVerbose();
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return list<array<string,mixed>>
     */
    protected function orderedChecksForDisplay(array $checks): array
    {
        usort($checks, static function (array $left, array $right): int {
            $rank = [
                'fail' => 0,
                'warn' => 1,
                'pass' => 2,
            ];

            $leftStatus = (string) ($left['status'] ?? 'pass');
            $rightStatus = (string) ($right['status'] ?? 'pass');
            $leftRank = $rank[$leftStatus] ?? 3;
            $rightRank = $rank[$rightStatus] ?? 3;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) ($left['check'] ?? ''), (string) ($right['check'] ?? ''));
        });

        return $checks;
    }

    /**
     * @param  list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>  $indicators
     */
    protected function overallSloStatus(array $indicators): string
    {
        foreach ($indicators as $indicator) {
            if ($indicator['status'] === 'fail') {
                return 'fail';
            }
        }

        foreach ($indicators as $indicator) {
            if ($indicator['status'] === 'warn') {
                return 'warn';
            }
        }

        return 'pass';
    }

    /**
     * @return array{requested:int,effective:int}
     */
    protected function recentRunLimits(): array
    {
        $requestedLimit = max(1, (int) $this->option('limit'));
        $configuredCap = max(1, (int) $this->config->get('checkpoint.reporting.max_recent_runs', 100));

        return [
            'requested' => $requestedLimit,
            'effective' => min($requestedLimit, $configuredCap),
        ];
    }

    protected function translatedOr(string $key, string $default, array $replace = []): string
    {
        $value = (string) __($key, $replace);

        return $value !== $key ? $value : $default;
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
