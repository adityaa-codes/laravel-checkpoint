<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\FileHelpersToFileFacadeRector;
use Utils\Rector\Rector\NullCheckToNullsafeRector;
use Utils\Rector\Rector\RemoveRedundantTypeChecksRector;
use Utils\Rector\Rector\RemoveUnnecessaryTypeCastsRector;
use Utils\Rector\Rector\StrHelpersToStrFacadeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/tests',
    ])
    ->withRules([
        FileHelpersToFileFacadeRector::class,
        NullCheckToNullsafeRector::class,
        RemoveRedundantTypeChecksRector::class,
        RemoveUnnecessaryTypeCastsRector::class,
        StrHelpersToStrFacadeRector::class,
    ]);
