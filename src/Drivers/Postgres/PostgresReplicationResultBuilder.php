<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers\Postgres;

use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;

/** @internal */
final readonly class PostgresReplicationResultBuilder
{
    public function __construct(
        private PostgresReplicationDebugRenderer $debugRenderer,
        private ReplicationFailureSuggestionMapper $suggestionMapper,
    ) {}

    /**
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $dryRun
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function dryRunFailure(array $replication, array $dryRun): array
    {
        $analysis = $this->suggestionMapper->map('dry_run_export', $dryRun['output'], [
            'engine' => $replication['engine'] ?? null,
            'source' => $replication['source']['redacted'] ?? null,
            'destination' => $replication['destination']['redacted'] ?? null,
        ]);

        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output'].$this->debugRenderer->render($analysis),
            'exit_code' => $dryRun['exit_code'],
            'metadata' => [
                ...$dryRun['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'failed',
                    'failure_analysis' => $analysis,
                    'failure_context' => [
                        'stage' => 'dry_run_export',
                        'reason' => 'postgres dry-run export command failed.',
                        'suggestions' => $this->legacySuggestions($analysis),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $dryRun
     * @param  array<string, mixed>|null  $sourceSnapshot
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function dryRunSuccess(array $replication, array $dryRun, ?array $sourceSnapshot): array
    {
        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output'],
            'exit_code' => 0,
            'metadata' => [
                ...$dryRun['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'dry_run_only',
                    'sanity' => [
                        'source_snapshot' => $sourceSnapshot,
                        'method' => 'artifact_hash',
                        'destination_check' => 'skipped',
                        'fallback_reason' => 'apply_not_requested_or_dry_run_enforced',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $dryRun
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function applyGateFailure(array $replication, array $dryRun): array
    {
        $analysis = $this->suggestionMapper->map('apply_gate', 'Destination overwrite denied by policy.', [
            'engine' => $replication['engine'] ?? null,
            'source' => $replication['source']['redacted'] ?? null,
            'destination' => $replication['destination']['redacted'] ?? null,
            'overwrite_destination' => (bool) ($replication['overwrite_destination'] ?? false),
            'force_requested' => (bool) ($replication['force_requested'] ?? false),
        ]);

        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                ."\n[replication_sync:apply_gate]\nDestination overwrite denied by policy."
                .$this->debugRenderer->render($analysis),
            'exit_code' => 2,
            'metadata' => [
                ...$dryRun['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'failed',
                    'failure_analysis' => $analysis,
                    'failure_context' => [
                        'stage' => 'apply_gate',
                        'reason' => 'Destination overwrite is blocked by default.',
                        'suggestions' => $this->legacySuggestions($analysis),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $dryRun
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $apply
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function applyFailure(array $replication, array $dryRun, array $apply, array $sanity): array
    {
        $analysis = $this->suggestionMapper->map('apply_restore', $apply['output'], [
            'engine' => $replication['engine'] ?? null,
            'source' => $replication['source']['redacted'] ?? null,
            'destination' => $replication['destination']['redacted'] ?? null,
            'sanity_method' => $sanity['method'],
        ]);

        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                ."\n[replication_sync:apply]\n".$apply['output']
                .$this->debugRenderer->render($analysis),
            'exit_code' => $apply['exit_code'],
            'metadata' => [
                ...$dryRun['metadata'],
                ...$apply['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'failed',
                    'sanity' => $sanity,
                    'failure_analysis' => $analysis,
                    'failure_context' => [
                        'stage' => 'apply_restore',
                        'reason' => 'pg_restore apply phase failed on destination.',
                        'suggestions' => $this->legacySuggestions($analysis),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $dryRun
     * @param  array{output:string,exit_code:int,metadata:array<string,mixed>}  $apply
     * @return array{output:string,exit_code:int,metadata:array<string,mixed>}
     */
    public function applySuccess(array $replication, array $dryRun, array $apply, array $sanity): array
    {
        return [
            'output' => "[replication_sync:dry_run]\n".$dryRun['output']
                ."\n[replication_sync:apply]\n".$apply['output'],
            'exit_code' => 0,
            'metadata' => [
                ...$dryRun['metadata'],
                ...$apply['metadata'],
                'replication' => [
                    ...$replication,
                    'result' => 'applied',
                    'sanity' => $sanity,
                ],
            ],
        ];
    }

    /**
     * @param  array{immediate_fix:string, deeper_diagnostics:list<string>}  $analysis
     * @return list<string>
     */
    private function legacySuggestions(array $analysis): array
    {
        return [
            $analysis['immediate_fix'],
            ...$analysis['deeper_diagnostics'],
        ];
    }
}
