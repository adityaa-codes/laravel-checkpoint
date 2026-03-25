<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;

it('requires explicit confirmation outside ci contexts', function (): void {
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', null);
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
    config()->set('checkpoint.restore.confirmation_token', null);
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
    config()->set('checkpoint.restore.confirmation_token', null);
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
        'operation' => 'pgbackrest_restore',
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
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->toThrow(ConfigurationException::class, 'Restore operation [pgbackrest_restore] requires a verified backup signal before execution.');
});

it('accepts restores when a matching verified backup signal exists', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'backup_label' => '20260312-010101F',
        'verification_state' => 'verified',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'repository' => 1,
        'stanza' => 'main',
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
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
        ->and($audit['restore_audit']['verified_signal_operation'])->toBe('pgbackrest_check')
        ->and($audit['restore_audit']['verified_signal_backup_label'])->toBe('20260312-010101F')
        ->and($audit['restore_audit']['verified_signal_artifact_path'])->toBeNull()
        ->and($audit['restore_audit']['verified_signal_last_known_good_at'])->toBeString();
});

it('does not treat pgbackrest info runs as a verified restore signal', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'backup_label' => '20260312-010101F',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'repository' => 1,
        'stanza' => 'main',
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'repository' => 1,
        'stanza' => 'main',
    ]))->toThrow(ConfigurationException::class, 'Restore operation [pgbackrest_restore] requires a verified backup signal before execution.');
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

it('requires pgbackrest verification to match the configured stanza and repository', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'backup_label' => '20260312-010101F',
        'verification_state' => 'verified',
        'driver_name' => 'pgbackrest',
        'repository' => 2,
        'stanza' => 'archive',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'repository' => 1,
        'stanza' => 'main',
    ]))->toThrow(ConfigurationException::class, 'Restore operation [pgbackrest_restore] requires a verified backup signal before execution.');
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

it('requires pgbackrest verification to match driver provenance', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);
    config()->set('checkpoint.restore.require_confirmation', true);
    config()->set('checkpoint.restore.confirmation_phrase', 'CONFIRM-RESTORE');
    config()->set('checkpoint.restore.confirmation_token', 'CONFIRM-RESTORE');

    CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'backup_label' => '20260312-010101F',
        'verification_state' => 'verified',
        'driver_name' => 'pgdump',
        'repository' => 1,
        'stanza' => 'main',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run, [
        'repository' => 1,
        'stanza' => 'main',
        'metadata' => [
            'driver' => 'pgbackrest',
            'database' => ':memory:',
        ],
    ]))->toThrow(ConfigurationException::class, 'Restore operation [pgbackrest_restore] requires a verified backup signal before execution.');
});

it('requires an explicit pgbackrest backup label when verified backup enforcement is enabled', function (): void {
    config()->set('checkpoint.restore.require_verified_backup', true);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => null,
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->toThrow(
            ConfigurationException::class,
            'pgbackrest_restore requires an explicit backup set label when checkpoint.restore.require_verified_backup is enabled.',
        );
});
