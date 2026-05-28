<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector;
use RectorLaravel\Set\LaravelSetProvider;
use Utils\Rector\Rector\ArrayHelpersToArrFacadeRector;
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
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true)
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
        typeDeclarations: true,
    )
    ->withRules([
        ArrayHelpersToArrFacadeRector::class,
        FileHelpersToFileFacadeRector::class,
        NullCheckToNullsafeRector::class,
        RemoveRedundantTypeChecksRector::class,
        RemoveUnnecessaryTypeCastsRector::class,
        StrHelpersToStrFacadeRector::class,
    ])
    ->withSkip([
        RemoveUnusedPublicMethodParameterRector::class,
        StrictArrayParamDimFetchRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,
        RemoveUnusedConstructorParamRector::class,
        __DIR__.'/src/Drivers/MysqlDriver.php',
    ]);
