<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

trait UsesLaravelPrompts
{
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }
}
