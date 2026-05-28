<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

/** @internal */
final readonly class BinaryFinder
{
    /**
     * @return array{found:bool,path:?string}
     */
    public function resolve(string $binary): array
    {
        $trimmed = Str::trim($binary);

        if ($trimmed === '') {
            return ['found' => false, 'path' => null];
        }

        if (is_executable($trimmed)) {
            return ['found' => true, 'path' => $trimmed];
        }

        $path = (new ExecutableFinder)->find($trimmed);

        return $path === null
            ? ['found' => false, 'path' => null]
            : ['found' => true, 'path' => $path];
    }
}
