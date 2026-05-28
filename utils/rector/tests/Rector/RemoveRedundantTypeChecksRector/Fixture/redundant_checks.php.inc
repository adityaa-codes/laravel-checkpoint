<?php

declare(strict_types=1);

function greet(string $name): string
{
    if (is_string($name)) {
        return 'Hello ' . $name;
    }
    return '';
}

function processArray(array $items): int
{
    return is_array($items) ? count($items) : 0;
}
-----
<?php

declare(strict_types=1);

function greet(string $name): string
{
    if (true) {
        return 'Hello ' . $name;
    }
    return '';
}

function processArray(array $items): int
{
    return true ? count($items) : 0;
}
