<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

trait RendersJsonOutput
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function renderJson(string $surface, array $data, int $exitCode = 0): int
    {
        $envelope = [
            'version' => 1,
            'surface' => $surface,
            'status' => $exitCode === 0 ? 'ok' : 'error',
            'generated_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }
}
