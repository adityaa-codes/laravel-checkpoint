<?php

declare(strict_types=1);

function getName(): string
{
    return 'hello';
}

function getInt(): int
{
    return 42;
}

$name = (string) getName();
$count = (int) getInt();
$flag = (bool) true;
-----
<?php

declare(strict_types=1);

function getName(): string
{
    return 'hello';
}

function getInt(): int
{
    return 42;
}

$name = getName();
$count = getInt();
$flag = true;
