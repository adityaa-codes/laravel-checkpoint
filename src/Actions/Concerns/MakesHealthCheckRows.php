<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions\Concerns;

trait MakesHealthCheckRows
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function checkRow(string $code, string $check, string $status, string $notes, array $data = []): array
    {
        return [
            'code' => $code,
            'check' => $check,
            'status' => $status,
            'severity' => $this->severityForStatus($status),
            'notes' => $notes,
            'data' => $data,
        ];
    }

    private function severityForStatus(string $status): string
    {
        return match ($status) {
            'fail' => 'blocker',
            'warn' => 'warning',
            default => 'info',
        };
    }
}
