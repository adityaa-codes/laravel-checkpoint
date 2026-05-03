<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

/** @internal */
final readonly class StringListFormatter
{
    /**
     * @return list<string>
     */
    public function format(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
