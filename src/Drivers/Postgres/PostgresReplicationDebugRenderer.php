<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

/** @internal */
final class PostgresReplicationDebugRenderer
{
    /**
     * @param  array{category:string, immediate_fix:string, deeper_diagnostics:list<string>}  $analysis
     */
    public function render(array $analysis): string
    {
        $lines = [
            '',
            '[replication_sync:debug]',
            sprintf('category: %s', $analysis['category']),
            sprintf('immediate_fix: %s', $analysis['immediate_fix']),
        ];

        foreach ($analysis['deeper_diagnostics'] as $index => $step) {
            $lines[] = sprintf('diagnostic_%d: %s', $index + 1, $step);
        }

        return "\n".collect($lines)->join("\n");
    }
}
