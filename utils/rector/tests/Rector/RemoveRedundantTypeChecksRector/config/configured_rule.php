<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\RemoveRedundantTypeChecksRector;

return RectorConfig::configure()
    ->withRules([RemoveRedundantTypeChecksRector::class]);
