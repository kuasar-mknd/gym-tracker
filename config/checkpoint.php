<?php

use Checkpoint\Checks;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled Checks
    |--------------------------------------------------------------------------
    |
    | Every default check is listed here and enabled by default. Set any
    | entry to `false` to exclude it from `php artisan checkpoint:scan`.
    |
    | Checks not listed in this map fall back to enabled — so when you
    | upgrade Checkpoint and new checks are added, you keep the protection
    | without re-publishing this file.
    |
    */

    'checks' => [
        Checks\ComposerAuditCheck::class => true,
        Checks\NpmAuditCheck::class => true,
        Checks\EnvironmentCheck::class => true,
        Checks\GitIgnoreCheck::class => true,
        Checks\FilePermissionsCheck::class => true,
        Checks\HardcodedSecretsCheck::class => true,
        Checks\SqlInjectionCheck::class => true,
        Checks\MassAssignmentCheck::class => true,
        Checks\XssCheck::class => true,
        Checks\CsrfCheck::class => true,
        Checks\OpenRedirectCheck::class => true,
        Checks\CommandInjectionCheck::class => true,
        Checks\InsecureDeserializationCheck::class => true,
        Checks\DebugFunctionsCheck::class => true,
        Checks\SensitiveExposureCheck::class => true,
        Checks\SsrfCheck::class => true,
        Checks\TlsVerificationCheck::class => true,
        Checks\CorsConfigCheck::class => true,
        Checks\PackageFreshnessCheck::class => true,
        Checks\SuspiciousVendorAutoloadCheck::class => true,
        Checks\SupplyChainToolingCheck::class => true,
        Checks\PathTraversalCheck::class => true,
        Checks\WeakCryptographyCheck::class => true,
        Checks\InsecureRngCheck::class => true,
        Checks\SessionSecurityCheck::class => true,
        Checks\EolVersionCheck::class => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Checks
    |--------------------------------------------------------------------------
    |
    | Register custom AbstractCheck subclasses to run alongside the built-ins.
    | Classes may take no constructor args, or a single $basePath string.
    | You can still disable any entry via the `checks` map above.
    |
    */

    'extra_checks' => [
        // \App\Security\MyCustomCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Freshness (Supply Chain)
    |--------------------------------------------------------------------------
    |
    | Composer packages released within `minimum_age_days` will fail the
    | "Package Freshness" check. This mitigates supply-chain attacks that
    | typically get caught and removed from Packagist within hours or days.
    |
    | Add fully-qualified package names to `whitelist` to bypass the age
    | check for specific dependencies (e.g. a critical security patch you
    | need to deploy before the freshness window expires).
    |
    */

    'package_freshness' => [
        'minimum_age_days' => 3,
        'whitelist' => [
            // Checkpoint exempts itself from the freshness gate so a fresh
            // release of the scanner cannot block its own user's deploy.
            'andreapollastri/checkpoint',
            // 'vendor/package',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Vendor Autoload
    |--------------------------------------------------------------------------
    |
    | The "Suspicious Vendor Autoload" check warns when a package under
    | vendor/ registers PHP files via `autoload.files` — the exact mechanism
    | abused by the May 2026 Laravel-Lang supply-chain attack to execute
    | code on every request.
    |
    | A baked-in whitelist already covers packages that legitimately use
    | this mechanism (laravel/framework, symfony/polyfill-*, guzzlehttp/*,
    | ramsey/uuid, …). Add your own trusted entries below — exact matches
    | or `vendor/*` wildcards are both supported.
    |
    */

    'suspicious_autoload' => [
        'whitelist' => [
            'blade-ui-kit/blade-icons',
            'danharrin/date-format-converter',
            'evenement/evenement',
            'filament/filament',
            'filament/forms',
            'filament/notifications',
            'filament/support',
            'inertiajs/inertia-laravel',
            'laminas/laminas-diactoros',
            'laravel/agent-detector',
            'laravel/prompts',
            'league/container',
            'league/csv',
            'livewire/livewire',
            'mockery/mockery',
            'myclabs/deep-copy',
            'nunomaduro/collision',
            'nunomaduro/termwind',
            'pestphp/pest',
            'pestphp/pest-plugin-arch',
            'pestphp/pest-plugin-laravel',
            'php-webdriver/webdriver',
            'phpseclib/phpseclib',
            'phpstan/phpstan',
            'phpunit/phpunit',
            'pragmarx/google2fa',
            'psy/psysh',
            'ralouphie/getallheaders',
            'react/promise',
            'rector/rector',
            'scrivo/highlight.php',
            'sebastian/global-state',
            'sebastian/type',
            'sentry/sentry',
            'spatie/invade',
            'spatie/laravel-activitylog',
            'spatie/laravel-backup',
            'spatie/laravel-permission',
            'symfony/clock',
            'symfony/string',
            'symfony/translation',
            'symfony/var-dumper',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suppressed Findings
    |--------------------------------------------------------------------------
    |
    | Add 12-character finding hashes here to silence specific FAIL/WARN
    | issues you have intentionally accepted (false positive, legacy code,
    | etc.). Hashes are shown in square brackets next to each finding when
    | you run the scan — copy the bracketed value into this array.
    |
    | The hash is content-stable: refactors that only shift line numbers
    | will not invalidate it. Package Freshness hashes use package name +
    | version only, so they stay valid as the displayed release age changes.
    |
    | If every finding of a check is suppressed, the check is downgraded to
    | PASS with an explicit "N suppressed" message.
    |
    */

    'suppressed' => [
        // VolumeStatsService::getMonthlyVolumeHistory : le fragment interpolé
        // dans selectRaw() est choisi par le code selon le pilote (strftime ou
        // DATE_FORMAT), jamais par une entrée.
        '92c0a2c59b15',
        // .gitignore : `.env.*` couvre déjà `.env.backup` et `.env.production`,
        // que le contrôle attend en toutes lettres.
        '7040ff674a91',
        '0931c9e06f12',
        // Les vues de courriel publiées par Laravel dans resources/views/vendor,
        // hors dépôt (.gitignore) : leurs `{!! !!}` sont les siens, et le
        // contrôle des vues Blade ne lit pas `exclude_paths`.
        'bab84a53bda7',
        '93f37a90313f',
        '36900666b825',
        '05404b984556',
        '56a903076fc3',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Scan Paths
    |--------------------------------------------------------------------------
    |
    | Paths relative to the project root that file-based checks should skip.
    | Useful for mounted data directories or folders with different ownership
    | that are not part of your application source.
    |
    | Built-in exclusions (vendor/, node_modules/, storage/, …) always apply;
    | entries here are merged on top of those defaults.
    |
    */

    'exclude_paths' => [
        // 'storage/app/mounted-data',
        // 'data/external',
    ],

];
