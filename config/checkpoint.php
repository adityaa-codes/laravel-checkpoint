<?php

declare(strict_types=1);

return [
    'driver' => env('CP_DRIVER') ?: match (strtolower(trim((string) config('database.connections.'.config('database.default', 'mysql').'.driver', 'mysql')))) {
        'pgsql', 'postgres', 'postgresql' => 'postgres',
        'mysql', 'mariadb' => 'mysql',
        'sqlite' => 'shell',
        default => 'shell',
    },

    'queue' => [
        'name' => env('CP_QUEUE_NAME', 'db-ops'),
        'timeout' => (int) env('CP_QUEUE_TIMEOUT', 3600),
    ],

    'restore' => [
        'allowed_environments' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
        ), static fn (string $v): bool => $v !== '')),
        'allowed_databases' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('CP_RESTORE_ALLOWED_DATABASES', '')),
        ), static fn (string $v): bool => $v !== '')),
    ],

];
