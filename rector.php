<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])
    ->withPhpSets()
    ->withComposerBased(phpunit: true, symfony: true, doctrine: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__.'/tests/App/config',
        // "null === $x" reads better than "!$x instanceof \Fully\Qualified\Name".
        FlipTypeControlToUseExclusiveTypeRector::class,
        // Pure private helpers may stay static.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // dirname(__DIR__) keeps paths free of "..".
        DirnameDirConcatStringToDirectStringPathRector::class,
    ]);
