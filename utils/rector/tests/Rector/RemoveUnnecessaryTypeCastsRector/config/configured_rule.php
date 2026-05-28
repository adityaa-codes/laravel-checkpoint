<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\RemoveUnnecessaryTypeCastsRector;

return RectorConfig::configure()
    ->withRules([RemoveUnnecessaryTypeCastsRector::class]);
