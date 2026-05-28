<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

use Illuminate\Support\Str;

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

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && Str::trim($item) !== '') {
                $result[] = Str::trim($item);
            }
        }

        return $result;
    }
}
