<?php

declare(strict_types=1);

it('completes a full smoke pipeline', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'true');

    checkpoint_artisan('checkpoint:test --no-interaction')
        ->expectsOutputToContain('Install')
        ->expectsOutputToContain('Doctor')
        ->expectsOutputToContain('Backup smoke')
        ->assertSuccessful();
});

it('supports the test command', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'true');

    checkpoint_artisan('checkpoint:test --no-interaction')
        ->expectsOutputToContain('Install')
        ->assertSuccessful();
});

it('renders pipeline table with step results', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('checkpoint.drivers.shell.commands.logical_backup', 'true');

    checkpoint_artisan('checkpoint:test --no-interaction')
        ->expectsOutputToContain('Step')
        ->expectsOutputToContain('passed')
        ->assertSuccessful();
});
