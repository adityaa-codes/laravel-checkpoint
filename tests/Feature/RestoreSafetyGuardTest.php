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

    CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'backup_label' => '20260312-010101F',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'last_known_good_at' => now(),
    ]);

    $run = CommandRun::factory()->make([
        'operation' => 'pgbackrest_restore',
        'argument_text' => '20260312-010101F',
    ]);

    expect(fn (): mixed => resolve(RestoreSafetyGuard::class)->ensureSafe($run))
        ->not->toThrow(ConfigurationException::class);
});
