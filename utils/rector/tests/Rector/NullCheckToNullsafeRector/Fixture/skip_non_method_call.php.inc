<?php

declare(strict_types=1);

$arg = 'hello';
$result = $arg !== null ? str($arg)->trim()->value() : null;
