<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enlightn Analyzers To Ignore
    |--------------------------------------------------------------------------
    |
    | Here you may specify the analyzers that you would like to ignore. You
    | should use the class name of the analyzer.
    |
    */

    'exclude_analyzers' => [
        \Enlightn\Enlightn\Analyzers\Performance\UnusedGlobalMiddlewareAnalyzer::class,
        \Enlightn\Enlightn\Analyzers\Security\FilePermissionsAnalyzer::class,
        \Enlightn\Enlightn\Analyzers\Security\PHPIniAnalyzer::class,
        \Enlightn\Enlightn\Analyzers\Security\StableDependencyAnalyzer::class,
        \Enlightn\Enlightn\Analyzers\Security\XSSAnalyzer::class,
    ],

];
