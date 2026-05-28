<?php

declare(strict_types=1);

function checkMixed(mixed $value): bool
{
    return is_string($value);
}
