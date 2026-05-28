<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\Postgres\PostgresPhysicalRestoreHandler;

it('resolves the physical restore handler from the container', function (): void {
    $handler = app(PostgresPhysicalRestoreHandler::class);

    expect($handler)->toBeInstanceOf(PostgresPhysicalRestoreHandler::class);
});
