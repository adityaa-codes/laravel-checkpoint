<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\NullCheckToNullsafeRector;

return RectorConfig::configure()
    ->withRules([NullCheckToNullsafeRector::class]);
