<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

/** @internal */
final class CommandJsonContract
{
    /**
     * @var array<string, int>
     */
    private const array SURFACE_VERSIONS = [
        'catalog_export' => 1,
        'doctor' => 3,
        'pitr_readiness' => 1,
        'retention_policy' => 1,
        'report' => 2,
        'status' => 1,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function envelope(string $surface, array $payload): array
    {
        return [
            ...$payload,
            'version' => self::SURFACE_VERSIONS[$surface] ?? 1,
            'surface' => $surface,
        ];
    }
}
