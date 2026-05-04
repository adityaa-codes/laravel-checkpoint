<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;

it('requires explicit confirmation outside ci contexts', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token');
    config()->set('checkpoint.restore.ci', false);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, ['restore_target' => 'nightly.sql']))
        ->toThrow(ConfigurationException::class, 'Restore confirmation is required.');
});

it('allows restore execution in ci when the ci bypass is enabled', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token');
    config()->set('checkpoint.restore.allow_in_ci', true);
    config()->set('checkpoint.restore.ci', true);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, ['restore_target' => 'nightly.sql']))
        ->not->toThrow(ConfigurationException::class);
});

it('blocks restore execution in ci when ci bypass is disabled', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token');
    config()->set('checkpoint.restore.allow_in_ci', false);
    config()->set('checkpoint.restore.ci', true);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, ['restore_target' => 'nightly.sql']))
        ->toThrow(ConfigurationException::class, 'Restore confirmation is required.');
});

it('blocks restore execution in disallowed environments', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.restore.allowed_environments', ['staging']);

    $run = CommandRun::factory()->make([
        'operation' => 'physical_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->toThrow(ConfigurationException::class, 'Restore operations are blocked in environment [production].');
});

it('blocks restore execution for disallowed databases', function (): void {
    config()->set('checkpoint.restore.allowed_databases', ['checkpoint_shadow']);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, ['restore_target' => 'nightly.sql']))
        ->toThrow(ConfigurationException::class, 'Restore operations are blocked for database [:memory:].');
});

it('rejects invalid pitr restore timestamps', function (): void {
    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => 'not-a-timestamp',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->toThrow(ConfigurationException::class, 'pitr_restore target [not-a-timestamp] must be a valid datetime string.');
});

it('requires a verified backup signal when configured for restores', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);

    $run = CommandRun::factory()->make([
        'operation' => 'physical_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [physical_restore] requires a verified backup signal before execution.');
});

it('accepts restores when a matching verified backup signal exists', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'physical_backup',
        'backup_label' => '20260312-010101F',
        'verification_state' => 'verified',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'repository' => 1,
        'stanza' => 'main',
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'physical_restore',
        'argument_text' => '20260312-010101F',
    ]);

    $audit = resolve(RestoreSafetyGuard::class)->ensureSafe($run);

    expect($audit['restore_audit'])->toBeArray()
        ->and($audit['restore_audit']['environment'])->toBe((string) config('app.env'))
        ->and($audit['restore_audit']['database'])->toBe(':memory:')
        ->and($audit['restore_audit']['target'])->toBe('20260312-010101F')
        ->and($audit['restore_audit']['confirmation_required'])->toBeTrue()
        ->and($audit['restore_audit']['confirmation_satisfied_via'])->toBe('token')
        ->and($audit['restore_audit']['verified_backup_required'])->toBeTrue()
        ->and($audit['restore_audit']['verified_signal_run_id'])->toBeInt()
        ->and($audit['restore_audit']['verified_signal_operation'])->toBe('physical_backup')
        ->and($audit['restore_audit']['verified_signal_backup_label'])->toBe('20260312-010101F')
        ->and($audit['restore_audit']['verified_signal_artifact_path'])->toBeNull()
        ->and($audit['restore_audit']['verified_signal_last_known_good_at'])->toBeString()
        ->and($audit['restore_audit']['blast_radius'])->toBeArray()
        ->and($audit['restore_audit']['blast_radius']['status'])->toBe('pass')
        ->and($audit['restore_audit']['blast_radius']['score'])->toBeInt()
        ->and($audit['restore_audit']['post_restore_verification'] ?? null)->toBeNull();
});

it('requires a matching artifact snapshot for verified logical restores', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => '/tmp/checkpoint-tests/logical-export-42',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
        'metadata' => [
            'driver' => 'pgdump',
            'database' => ':memory:',
            'artifact_snapshot' => [
                'path' => '/tmp/checkpoint-tests/logical-export-42',
                'file_type' => 'directory',
                'device' => 1,
                'inode' => 11,
                'mtime' => 1700000000,
                'size' => null,
                'content_signature' => 'before',
            ],
        ],
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'logical-export-42',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '/tmp/checkpoint-tests/logical-export-42',
        'restore_target_snapshot' => [
            'path' => '/tmp/checkpoint-tests/logical-export-42',
            'file_type' => 'directory',
            'device' => 1,
            'inode' => 11,
            'mtime' => 1700000001,
            'size' => null,
            'content_signature' => 'after',
        ],
        'metadata' => [
            'driver' => 'pgdump',
            'database' => ':memory:',
        ],
    ]))->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_file] requires a verified backup signal before execution.');
});

it('requires logical restore verification to match the driver provenance', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'artifact_path' => '/tmp/checkpoint-tests/logical-export-42',
        'driver_name' => 'mysql',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
            'artifact_snapshot' => [
                'path' => '/tmp/checkpoint-tests/logical-export-42',
                'file_type' => 'directory',
                'device' => 1,
                'inode' => 11,
                'mtime' => 1700000000,
                'size' => null,
                'content_signature' => 'before',
            ],
        ],
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_file',
        'argument_text' => 'logical-export-42',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '/tmp/checkpoint-tests/logical-export-42',
        'restore_target_snapshot' => [
            'path' => '/tmp/checkpoint-tests/logical-export-42',
            'file_type' => 'directory',
            'device' => 1,
            'inode' => 11,
            'mtime' => 1700000000,
            'size' => null,
            'content_signature' => 'before',
        ],
        'metadata' => [
            'driver' => 'pgdump',
            'database' => ':memory:',
        ],
    ]))->toThrow(ConfigurationException::class, 'Restore operation [logical_restore_file] requires a verified backup signal before execution.');
});

it('requires pitr restore verification to match mysql provenance', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'driver_name' => 'pgdump',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
        'metadata' => [
            'driver' => 'pgdump',
            'database' => ':memory:',
        ],
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => '2026-03-24T11:00:00+00:00',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '2026-03-24T11:00:00+00:00',
        'pitr_base_target' => '/tmp/checkpoint-tests/mysql-baseline.sql',
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
            'binlog_files' => ['/var/lib/mysql/binlog.000001'],
        ],
    ]))->toThrow(ConfigurationException::class, 'Restore operation [pitr_restore] requires a verified backup signal before execution.');
});

it('accepts pitr restore verification when provenance matches mysql backup metadata', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'driver_name' => 'mysql',
        'artifact_path' => '/tmp/checkpoint-tests/mysql-baseline.sql',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
        ],
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => '2026-03-24T11:00:00+00:00',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '2026-03-24T11:00:00+00:00',
        'pitr_base_target' => '/tmp/checkpoint-tests/mysql-baseline.sql',
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
            'binlog_files' => ['/var/lib/mysql/binlog.000001', '/var/lib/mysql/binlog.000002'],
        ],
    ]))->not->toThrow(ConfigurationException::class);
});

it('requires pitr verification to include a baseline artifact and binlog chain context', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    $run = CommandRun::factory()->make([
        'operation' => 'pitr_restore',
        'argument_text' => '2026-03-24T11:00:00+00:00',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '2026-03-24T11:00:00+00:00',
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
        ],
    ]))->toThrow(ConfigurationException::class, 'pitr_restore requires a baseline logical backup artifact when checkpoint.restore.require_verified_backup is enabled.');

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '2026-03-24T11:00:00+00:00',
        'pitr_base_target' => '/tmp/checkpoint-tests/mysql-baseline.sql',
        'metadata' => [
            'driver' => 'mysql',
            'database' => ':memory:',
            'binlog_files' => [],
        ],
    ]))->toThrow(ConfigurationException::class, 'pitr_restore requires a non-empty binlog chain when checkpoint.restore.require_verified_backup is enabled.');
});

it('records append-only restore decision evidence for blocked restore checks', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.restore.allowed_environments', ['staging']);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, ['restore_target' => 'nightly.sql']))
        ->toThrow(ConfigurationException::class, 'Restore operations are blocked in environment [production].');

    $events = RestoreDecisionEvent::query()
        ->where('command_run_id', $run->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->decision)->toBe('evaluate')
        ->and($events[0]->reason)->toBe('restore_safety_evaluated')
        ->and($events[1]->decision)->toBe('block')
        ->and($events[1]->reason)->toBe('restore_safety_blocked')
        ->and($events[1]->payload['message'] ?? null)->toBe('Restore operations are blocked in environment [production].');
});

it('records append-only restore decision evidence for successful restore checks', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.require_verified_backup', true);

    $verified = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'backup_label' => '20260312-010101F',
        'verification_state' => 'verified',
        'driver_name' => 'pgbasebackup',
        'repository' => 1,
        'stanza' => 'main',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
        'metadata' => [
            'driver' => 'pgbasebackup',
            'database' => ':memory:',
        ],
    ]);

    $run = CommandRun::query()->create([
        'operation' => 'physical_restore',
        'argument_text' => '20260312-010101F',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $audit = resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => '20260312-010101F',
        'repository' => 1,
        'stanza' => 'main',
        'metadata' => [
            'driver' => 'pgbasebackup',
            'database' => ':memory:',
        ],
    ]);

    $events = RestoreDecisionEvent::query()
        ->where('command_run_id', $run->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->decision)->toBe('evaluate')
        ->and($events[1]->decision)->toBe('allow')
        ->and($events[1]->reason)->toBe('restore_safety_passed')
        ->and($events[1]->payload['restore_audit']['verified_signal_run_id'] ?? null)->toBe((int) $verified->id)
        ->and($events[1]->payload['restore_audit']['blast_radius']['status'] ?? null)->toBeString()
        ->and($audit['restore_audit']['verified_signal_run_id'])->toBe((int) $verified->id);
});

it('blocks restore execution when blast radius score exceeds block threshold', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.restore.allowed_environments', ['production']);
    config()->set('checkpoint.restore.allowed_databases', [':memory:']);
    config()->set('checkpoint.restore.require_confirmation', false);
    config()->set('checkpoint.restore.require_verified_backup', false);
    config()->set('checkpoint.restore.blast_radius.enabled', true);
    config()->set('checkpoint.restore.blast_radius.warn_score', 30);
    config()->set('checkpoint.restore.blast_radius.block_score', 50);
    config()->set('checkpoint.restore.blast_radius.weights', [
        'environment' => 30,
        'database' => 25,
        'target' => 20,
        'verification' => 25,
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
        'argument_text' => 'latest',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => 'latest',
    ]))->toThrow(ConfigurationException::class, 'Restore blast radius score [75] exceeds block threshold [50].');
});

it('allows restore with warn blast radius status when below block threshold', function (): void {
    config()->set('app.env', 'production');
    config()->set('checkpoint.restore.allowed_environments', ['production']);
    config()->set('checkpoint.restore.allowed_databases', [':memory:']);
    config()->set('checkpoint.restore.require_confirmation', false);
    config()->set('checkpoint.restore.require_verified_backup', false);
    config()->set('checkpoint.restore.blast_radius.enabled', true);
    config()->set('checkpoint.restore.blast_radius.warn_score', 30);
    config()->set('checkpoint.restore.blast_radius.block_score', 80);
    config()->set('checkpoint.restore.blast_radius.weights', [
        'environment' => 30,
        'database' => 0,
        'target' => 20,
        'verification' => 25,
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'logical_restore_latest',
        'argument_text' => 'latest',
    ]);

    $audit = resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'restore_target' => 'latest',
    ]);

    expect($audit['restore_audit']['blast_radius']['status'] ?? null)->toBe('warn')
        ->and($audit['restore_audit']['blast_radius']['score'] ?? null)->toBe(50);
});
