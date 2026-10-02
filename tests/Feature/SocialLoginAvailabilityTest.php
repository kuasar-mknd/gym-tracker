<?php

declare(strict_types=1);

use Laravel\Socialite\Facades\Socialite;

use function Pest\Laravel\get;

/**
 * A social button that cannot work must not be on the page.
 *
 * Both auth screens ask for `social_login_enabled` before drawing their three
 * buttons, and nothing ever shared it — so the fallback won every time and all
 * three were drawn unconditionally. Apple then answered 500 on every click: the
 * package sat in composer.json without ever being announced to Socialite, and
 * no credentials were configured either.
 *
 * Two things are checked here, because fixing one without the other leaves the
 * button broken: that the driver resolves at all, and that the page only offers
 * providers whose credentials are complete.
 */
it('resolves every provider the login page can offer', function (string $fournisseur): void {
    // Apple is a community package: Socialite has no createAppleDriver, so it
    // only exists once an event listener extends Socialite with it.
    expect(fn () => Socialite::driver($fournisseur))->not->toThrow(InvalidArgumentException::class);
})->with(['google', 'github', 'apple']);

it('offers a provider only when both halves of its credentials are set', function (): void {
    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        // A client id with no secret cannot complete the exchange. Offering the
        // button anyway sends the user to an error page instead of to the
        // provider — which is exactly what Apple did.
        'services.github.client_id' => 'id',
        'services.github.client_secret' => null,
        'services.apple.client_id' => null,
        'services.apple.client_secret' => null,
    ]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->where('social_login_enabled.google', true)
            ->where('social_login_enabled.github', false)
            ->where('social_login_enabled.apple', false)
        );
});

it('says so on the registration page too', function (): void {
    config([
        'services.google.client_id' => 'id',
        'services.google.client_secret' => 'secret',
        'services.github.client_id' => null,
        'services.github.client_secret' => null,
        'services.apple.client_id' => null,
        'services.apple.client_secret' => null,
    ]);

    // The registration page carried the same three buttons with no guard at
    // all, so it kept offering Apple even once the login page had stopped.
    $this->get(route('register'))
        ->assertInertia(fn ($page) => $page
            ->where('social_login_enabled.google', true)
            ->where('social_login_enabled.apple', false)
        );
});

/**
 * Relit config/services.php avec les variables données, comme le fait
 * `config:cache` au démarrage d'un conteneur.
 *
 * @param  array<array-key, mixed>  $variables
 * @return array<string, mixed>
 */
function connexionSocialeConfigurationRelue(array $variables): array
{
    $noms = ['APP_URL', 'GOOGLE_REDIRECT_URI', 'GITHUB_REDIRECT_URI', 'APPLE_REDIRECT_URI'];
    $avant = [];

    foreach ($noms as $nom) {
        $avant[$nom] = [$_SERVER[$nom] ?? null, $_ENV[$nom] ?? null, getenv($nom)];
        unset($_SERVER[$nom], $_ENV[$nom]);
        putenv($nom);
    }

    foreach ($variables as $nom => $valeur) {
        if (! is_string($nom) || ! is_string($valeur)) {
            continue;
        }

        $_SERVER[$nom] = $_ENV[$nom] = $valeur;
        putenv("{$nom}={$valeur}");
    }

    try {
        /** @var array<string, mixed> $configuration */
        $configuration = require config_path('services.php');

        return $configuration;
    } finally {
        foreach ($avant as $nom => [$serveur, $environnement, $processus]) {
            unset($_SERVER[$nom], $_ENV[$nom]);
            putenv($processus === false ? $nom : "{$nom}={$processus}");

            if ($serveur !== null) {
                $_SERVER[$nom] = $serveur;
            }

            if ($environnement !== null) {
                $_ENV[$nom] = $environnement;
            }
        }
    }
}

/*
 * Sans URL de rappel, Google et Apple refusent l'échange (#1908). La pile de
 * production transmet une chaîne vide pour une variable qu'elle ne pose pas,
 * et env() rend alors cette chaîne, pas son défaut : les deux cas comptent.
 */
it('déduit l\'URL de rappel d\'APP_URL quand rien ne la fixe', function (string $fournisseur, array $rappelPose): void {
    $configuration = connexionSocialeConfigurationRelue(['APP_URL' => 'https://gym.example.org/', ...$rappelPose]);

    expect(data_get($configuration, "{$fournisseur}.redirect"))->toBe("https://gym.example.org/auth/{$fournisseur}/callback");
})->with(['google', 'github', 'apple'])->with([
    'variable absente' => [[]],
    'variable vide, comme la transmet la composition' => [['GOOGLE_REDIRECT_URI' => '', 'GITHUB_REDIRECT_URI' => '', 'APPLE_REDIRECT_URI' => '']],
]);

it('garde l\'URL de rappel posée explicitement', function (): void {
    $configuration = connexionSocialeConfigurationRelue([
        'APP_URL' => 'https://gym.example.org',
        'GOOGLE_REDIRECT_URI' => 'https://autre.example.org/rappel',
    ]);

    expect(data_get($configuration, 'google.redirect'))->toBe('https://autre.example.org/rappel');
});

it('envoie le fournisseur vers l\'URL de rappel de la configuration', function (string $fournisseur): void {
    $rappel = config("services.{$fournisseur}.redirect");
    expect($rappel)->toBeString();
    assert(is_string($rappel));

    expect(get(route('social.redirect', $fournisseur))->headers->get('Location'))->toBeString()
        ->toContain('redirect_uri='.rawurlencode($rappel));
})->with(['google', 'github']);
