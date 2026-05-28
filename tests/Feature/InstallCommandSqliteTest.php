<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::partialMock();
});

it('refuses to install when database driver is sqlite', function (): void {
    config()->set('database.default', 'sqlite_sqlite');
    config()->set('database.connections.sqlite_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor --force')
        ->assertExitCode(1);
});

it('refuses to install when database driver is sqlsrv', function (): void {
    config()->set('database.default', 'sqlserver');
    config()->set('database.connections.sqlserver', [
        'driver' => 'sqlsrv',
    ]);

    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor --force')
        ->assertExitCode(1);
});

it('allows install when database driver is mysql', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'database' => 'test',
    ]);

    checkpoint_artisan('checkpoint:install --skip-publish --skip-migrate --skip-doctor --force')
        ->assertExitCode(0);
});
