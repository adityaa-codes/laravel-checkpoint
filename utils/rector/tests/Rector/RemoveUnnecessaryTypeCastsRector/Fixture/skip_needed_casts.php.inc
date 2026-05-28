<?php

declare(strict_types=1);

function getMixed(): mixed
{
    return 'hello';
}

$name = (string) getMixed();
$count = (int) getMixed();
