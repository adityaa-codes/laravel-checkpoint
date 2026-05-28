<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use Illuminate\Support\Arr;
use Illuminate\Support\Js;

final class ConfigShowCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:config:show {--key= : Show only a specific config key (dot notation).}';

    protected $description = 'Show the full resolved Checkpoint configuration with defaults.';

    public function handle(): int
    {
        $key = $this->stringOption('key');

        if ($key !== null && $key !== '') {
            return $this->showSingleKey($key);
        }

        return $this->showFullConfig();
    }

    private function showFullConfig(): int
    {
        $config = $this->resolveConfig();

        $this->line(Js::encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function showSingleKey(string $key): int
    {
        $config = $this->resolveConfig();
        $value = Arr::get($config, $key);

        if ($value === null && ! Arr::has($config, $key)) {
            $this->promptError(sprintf('Config key "%s" not found.', $key));

            return self::FAILURE;
        }

        if (is_array($value)) {
            $this->line(Js::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->promptTable(['Key', 'Value'], [
            [$key, $this->formatValue($value)],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfig(): array
    {
        return (array) config('checkpoint');
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_array($value)) {
            return Js::encode($value);
        }

        return (string) $value;
    }
}
