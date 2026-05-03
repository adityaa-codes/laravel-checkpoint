<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Support;

/** @internal */
final readonly class DsnPattern
{
    public const string REGEX = '/^[A-Za-z][A-Za-z0-9+.-]*:\/\//';
}
