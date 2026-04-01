<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;

it('maps known replication failure signatures to expected categories', function (): void {
    $mapper = app(ReplicationFailureSuggestionMapper::class);

    $cases = [
        ['output' => 'password authentication failed for user "replicator"', 'stage' => 'dry_run_export', 'category' => 'auth_credential_failure'],
        ['output' => 'connection refused', 'stage' => 'dry_run_export', 'category' => 'dns_network_connection_refused'],
        ['output' => 'permission denied for relation users', 'stage' => 'apply_import', 'category' => 'privilege_permission_denied'],
        ['output' => 'pg_dump: command not found', 'stage' => 'dry_run_export', 'category' => 'binary_missing'],
        ['output' => 'invalid DSN: failed to parse endpoint URL', 'stage' => 'dry_run_export', 'category' => 'invalid_url_dsn_parse'],
        ['output' => 'schema mismatch detected between source and destination', 'stage' => 'apply_restore', 'category' => 'schema_version_mismatch'],
        ['output' => 'destination overwrite denied by policy', 'stage' => 'apply_gate', 'category' => 'destination_overwrite_conflict'],
        ['output' => 'sanity check failed: checksum mismatch', 'stage' => 'apply_import', 'category' => 'checksum_sanity_verification_mismatch'],
    ];

    foreach ($cases as $case) {
        $analysis = $mapper->map($case['stage'], $case['output'], [
            'source' => 'pgsql://replicator:secret@source.internal/db',
            'destination' => 'pgsql://replicator:secret@dest.internal/db',
        ]);

        expect($analysis['category'])->toBe($case['category'])
            ->and($analysis['immediate_fix'])->toBeString()->not->toBe('')
            ->and($analysis['deeper_diagnostics'])->toBeArray()->not->toBeEmpty()
            ->and($analysis['diagnostics']['source'] ?? null)->toBe('pgsql://[REDACTED]@source.internal')
            ->and($analysis['diagnostics']['destination'] ?? null)->toBe('pgsql://[REDACTED]@dest.internal');
    }
});
