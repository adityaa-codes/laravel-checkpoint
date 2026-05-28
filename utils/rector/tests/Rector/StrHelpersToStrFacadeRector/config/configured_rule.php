<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\StrHelpersToStrFacadeRector;

return RectorConfig::configure()
    ->withRules([StrHelpersToStrFacadeRector::class]);
