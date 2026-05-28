<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationFailureSuggestionMapper;
use Illuminate\Support\Facades\File;

it('plans replication_sync metadata with explicit default safety gates', function (): void {
    $run = CommandRun::factory()->make([
        'id' => 7001,
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-source","destination":"pgsql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-source', 'redacted' => 'profile:pg-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://replicator:secret@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $handler = resolvePostgresReplicationSyncHandler();
    $metadata = $handler->plannedMetadata($run);

    expect($metadata['metadata']['replication']['engine'] ?? null)->toBe('pgsql')
        ->and($metadata['metadata']['replication']['dry_run_requested'] ?? null)->toBeTrue()
        ->and($metadata['metadata']['replication']['apply_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['force_requested'] ?? null)->toBeFalse()
        ->and($metadata['metadata']['replication']['overwrite_destination'] ?? null)->toBeFalse()
        ->and((string) ($metadata['metadata']['replication']['artifact_path'] ?? ''))->toContain('checkpoint-replication-7001.dump');
});

it('rejects replication_sync on Postgres driver when replication engine is not pgsql', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"mysql://[REDACTED]","destination":"mysql://[REDACTED]","dry_run":true}',
        'metadata' => [
            'replication' => [
                'engine' => 'mysql',
                'source' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://[REDACTED]'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'mysql://[REDACTED]'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $handler = resolvePostgresReplicationSyncHandler();

    expect(fn (): array => $handler->plannedMetadata($run))
        ->toThrow(ConfigurationException::class, 'Unsupported replication engine [mysql] for Postgres driver. Postgres driver supports pgsql -> pgsql only.');
});

it('runs replication_sync as dry-run-only by default and records conservative sanity metadata', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-dry-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', <<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --file=*)
      target="${arg#--file=}"
      printf 'replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH);
    File::chmod($binDir.'/pg_dump', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);
    $run->refresh();

    expect($context->isSuccessful())->toBeTrue()
        ->and($run->command_output)->toContain('[replication_sync:dry_run]')
        ->and($run->metadata['replication']['result'] ?? null)->toBe('dry_run_only')
        ->and($run->metadata['replication']['sanity']['method'] ?? null)->toBe('artifact_hash')
        ->and($run->metadata['replication']['sanity']['fallback_reason'] ?? null)->toBe('apply_not_requested_or_dry_run_enforced')
        ->and($run->metadata['replication']['destination']['redacted'] ?? null)->toBe('profile:pg-local');
});

it('adds structured failure analysis and debug suggestions for pg replication dry-run failures', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-fail-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', <<<'SH'
#!/bin/sh
echo 'could not translate host name "db.invalid" to address: Name or service not known'
exit 1
SH
    );
    File::chmod($binDir.'/pg_dump', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    $context = postgresContext($run);

    resolvePostgresDriver()->execute($context, $run);
    $run->refresh();

    expect($context->isSuccessful())->toBeFalse()
        ->and($run->command_output)->toContain('[replication_sync:debug]')
        ->and($run->command_output)->toContain('category: dns_network_connection_refused')
        ->and($run->command_output)->not->toContain('secret')
        ->and($run->metadata['replication']['failure_analysis']['category'] ?? null)->toBe('dns_network_connection_refused')
        ->and($run->metadata['replication']['failure_context']['suggestions'][0] ?? null)->toBe($run->metadata['replication']['failure_analysis']['immediate_fix'] ?? null);
});

it('fails replication_sync when endpoints indicate remote or cross-host semantics', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-source","destination":"pgsql://[REDACTED]","dry_run":true,"apply":false}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-source', 'redacted' => 'profile:pg-source'],
                'destination' => ['kind' => 'dsn', 'identifier' => null, 'redacted' => 'pgsql://[REDACTED]@db.internal'],
                'queue_only' => true,
                'dry_run_requested' => true,
            ],
        ],
    ]);

    expect(function () use ($run): void {
        $context = postgresContext($run);

        resolvePostgresDriver()->execute($context, $run);
    })
        ->toThrow(ConfigurationException::class, 'postgres replication execution currently supports only local/configured endpoint semantics.');
});

it('blocks Postgres replication apply when governance preflight disallows execution-time apply', function (): void {
    $outputDir = tempnam(sys_get_temp_dir(), 'checkpoint-postgres-repl-governance-');

    if ($outputDir === false) {
        throw new RuntimeException('Unable to allocate a temporary pgdump replication governance path.');
    }

    File::delete($outputDir);
    File::makeDirectory($outputDir, 0755, true);

    $binDir = sys_get_temp_dir().'/pg-bin-'.random_int(10000, 99999);
    File::makeDirectory($binDir, 0755);
    File::put($binDir.'/pg_dump', <<<'SH'
#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --file=*)
      target="${arg#--file=}"
      printf 'replication export payload' > "$target"
      ;;
  esac
done
printf 'dry-run export ok'
SH
    );
    File::chmod($binDir.'/pg_dump', 0755);
    config()->set('database.connections.pgsql.dump.dump_binary_path', $binDir);
    config()->set('checkpoint.drivers.postgres.output_dir', $outputDir);

    $run = CommandRun::query()->create([
        'operation' => 'replication_sync',
        'argument_text' => '{"source":"profile:pg-local","destination":"profile:pg-local","dry_run":false,"apply":true,"force_overwrite":true}',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'metadata' => [
            'replication' => [
                'engine' => 'pgsql',
                'source' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'destination' => ['kind' => 'config_profile', 'identifier' => 'pg-local', 'redacted' => 'profile:pg-local'],
                'queue_only' => true,
                'dry_run_requested' => false,
                'apply_requested' => true,
                'overwrite_destination' => true,
                'governance_preflight' => [
                    'allowed' => false,
                    'blocked_reasons' => ['destination_not_allowlisted'],
                ],
            ],
        ],
    ]);

    expect(function () use ($run): void {
        $context = postgresContext($run);

        resolvePostgresDriver()->execute($context, $run);
    })
        ->toThrow(ConfigurationException::class, 'Replication apply is blocked by governance preflight at execution time: destination_not_allowlisted.');
});

it('maps invalid dsn parse signatures in replication failure analysis', function (): void {
    $analysis = resolve(ReplicationFailureSuggestionMapper::class)->map(
        'dry_run_export',
        'invalid DSN: failed to parse endpoint URL',
        ['source' => 'pgsql://user:secret@db.internal/source'],
    );

    expect($analysis['category'])->toBe('invalid_url_dsn_parse')
        ->and($analysis['diagnostics']['source'] ?? null)->toBe('pgsql://[REDACTED]@db.internal');
});
