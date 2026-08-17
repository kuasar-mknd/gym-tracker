<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

uses(Tests\TestCase::class);

/**
 * Environment variable names declared in .env.example, commented-out ones included.
 *
 * @return list<string>
 */
function declaredInEnvExample(): array
{
    $contents = (string) file_get_contents(base_path('.env.example'));

    preg_match_all('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/m', $contents, $matches);

    return array_values(array_unique($matches[1]));
}

it('declares every VITE_ variable the frontend reads in .env.example', function (): void {
    $files = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.js', '*.vue']);

    $referenced = [];

    foreach ($files as $file) {
        /*
         * Les lignes de commentaire sont ecartees.
         *
         * main.js explique pourquoi il ne lit PAS de variable VITE_ pour le DSN
         * Sentry — Vite les substitue au build, donc la valeur partirait dans
         * l'image publique. Sans cette exclusion, ce garde exigeait de declarer
         * dans .env.example une variable que le code se refuse a lire, et un
         * garde qui punit sa propre documentation finit par etre affaibli.
         */
        $code = implode("\n", array_filter(
            explode("\n", $file->getContents()),
            static fn (string $ligne): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $ligne) !== 1,
        ));

        preg_match_all('/import\.meta\.env\.(VITE_[A-Z0-9_]*)/', $code, $matches);

        foreach ($matches[1] as $variable) {
            $referenced[$variable][] = $file->getRelativePathname();
        }
    }

    // Vite inlines these at build time: a variable absent from the template is
    // simply undefined, so the feature behind it is silently skipped rather
    // than failing loudly. That is how frontend Sentry stayed dark.
    $undeclared = array_diff_key($referenced, array_flip(declaredInEnvExample()));

    expect($undeclared)->toBe([]);
});

it('resolves a VAPID subject even when the variable is unset', function (): void {
    // minishlink/web-push throws "[VAPID] You must provide a subject" when this
    // is null, which fails every push notification at send time.
    expect(config('webpush.vapid.subject'))->not->toBeEmpty();
});

it('documents the VAPID keys push notifications require', function (string $variable): void {
    expect(declaredInEnvExample())->toContain($variable);
})->with(['VAPID_SUBJECT', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY']);
