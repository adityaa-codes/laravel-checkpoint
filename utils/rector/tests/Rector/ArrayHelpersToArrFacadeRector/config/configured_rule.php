<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\ArrayHelpersToArrFacadeRector;

return RectorConfig::configure()
    ->withRules([ArrayHelpersToArrFacadeRector::class]);
