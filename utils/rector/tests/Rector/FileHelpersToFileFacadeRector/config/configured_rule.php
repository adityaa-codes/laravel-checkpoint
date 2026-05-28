<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\FileHelpersToFileFacadeRector;

return RectorConfig::configure()
    ->withRules([FileHelpersToFileFacadeRector::class]);
