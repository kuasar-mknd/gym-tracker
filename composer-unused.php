<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

/*
 * composer-unused reports a package as unused when no symbol of it is imported
 * in the scanned source. In a Laravel application that is wrong far more often
 * than it is right: packages are wired through config files, service providers
 * and Blade directives, and never appear as a `use` statement.
 *
 * Scanning config/ and routes/ rescues most of them on its own — it takes the
 * list of false positives from six down to two — which is why the exceptions
 * below are so few. Each one names WHERE the package is actually used, so a
 * future reader can re-check the claim rather than trust the list.
 *
 * Keep this file in step with composer.json: a filter whose package is no longer
 * installed is reported as a "zombie exclusion" and fails the build just as an
 * unused package does.
 */
return static function (Configuration $config): Configuration {
    $rootPackage = json_decode(
        (string) file_get_contents(__DIR__.'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    )['name'];

    // Read from composer.json rather than hardcoded: renaming the project would
    // otherwise silently detach this block and bring the six false positives back.
    $config->setAdditionalFilesFor($rootPackage, [
        ...glob(__DIR__.'/config/*.php'),
        ...glob(__DIR__.'/routes/*.php'),
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
    ]);

    return $config
        // Resolved by string, never imported: Socialite::driver('apple') in
        // HandleSocialCallbackAction, 'apple' in SocialAuthController::ALLOWED_PROVIDERS,
        // and the credentials block at config/services.php:52. The package registers
        // its driver from its own auto-discovered provider.
        ->addNamedFilter(NamedFilter::fromString('socialiteproviders/apple'))
        // Used through the @routes Blade directive at resources/views/app.blade.php:42,
        // which no static analysis of PHP imports can see.
        ->addNamedFilter(NamedFilter::fromString('tightenco/ziggy'));
};
