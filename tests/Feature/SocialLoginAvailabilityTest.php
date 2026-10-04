<?php

declare(strict_types=1);

use Laravel\Socialite\Facades\Socialite;
use Tests\Support\ConfigurationDesServicesRelue;

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

/*
 * Apple complète son identité autrement (#1911) : à la place d'un secret signé
 * à la main, qui expire au bout de six mois au plus, le trio qui en signe un
 * neuf à chaque échange. Le bouton doit apparaître avec lui seul, et rester
 * masqué tant qu'il manque une pièce, faute de secret posé : un trio
 * incomplet ne signe rien.
 */
it('propose Apple avec le trio qui signe son secret, sans secret posé', function (): void {
    config([
        'services.apple.client_id' => 'org.example.gym.web',
        'services.apple.client_secret' => null,
        'services.apple.team_id' => 'EQUIPE0001',
        'services.apple.key_id' => 'CLEAPP0001',
        'services.apple.private_key' => 'le contenu du .p8 de test',
    ]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('social_login_enabled.apple', true));
});

it('masque Apple tant que son identité est incomplète', function (array $reglages): void {
    config([
        'services.apple.client_id' => 'org.example.gym.web',
        'services.apple.client_secret' => null,
        'services.apple.team_id' => 'EQUIPE0001',
        'services.apple.key_id' => 'CLEAPP0001',
        'services.apple.private_key' => 'le contenu du .p8 de test',
    ]);

    foreach ($reglages as $cle => $valeur) {
        config([(string) $cle => $valeur]);
    }

    $this->get(route('register'))
        ->assertInertia(fn ($page) => $page->where('social_login_enabled.apple', false));
})->with([
    'rien de posé' => [['services.apple.client_id' => null, 'services.apple.team_id' => null, 'services.apple.key_id' => null, 'services.apple.private_key' => null]],
    'sans Services ID' => [['services.apple.client_id' => null]],
    'sans Services ID, secret posé' => [['services.apple.client_id' => null, 'services.apple.client_secret' => 'secret']],
    'sans équipe' => [['services.apple.team_id' => null]],
    'sans identifiant de clé' => [['services.apple.key_id' => null]],
    'sans clé privée' => [['services.apple.private_key' => null]],
    'clé privée vide, comme la transmet la composition' => [['services.apple.private_key' => '']],
]);

/*
 * Le trio ne vaut que pour Apple : Google et GitHub ne savent pas signer de
 * secret, et le paquet d'Apple seul le lit. Le compter pour eux rouvrirait
 * #1908 : leur bouton s'afficherait avec le seul client id, et mènerait à une
 * page d'erreur au lieu de chez le fournisseur.
 */
it('ne tient pas le trio d\'Apple pour le secret de Google ou de GitHub', function (): void {
    config([
        'services.apple.client_id' => 'org.example.gym.web',
        'services.apple.client_secret' => null,
        'services.apple.team_id' => 'EQUIPE0001',
        'services.apple.key_id' => 'CLEAPP0001',
        'services.apple.private_key' => 'le contenu du .p8 de test',
        'services.google.client_id' => 'id',
        'services.google.client_secret' => null,
        'services.github.client_id' => 'id',
        'services.github.client_secret' => null,
    ]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->where('social_login_enabled.apple', true)
            ->where('social_login_enabled.google', false)
            ->where('social_login_enabled.github', false)
        );
});

/*
 * Sans URL de rappel, Google et Apple refusent l'échange (#1908). La pile de
 * production transmet une chaîne vide pour une variable qu'elle ne pose pas,
 * et env() rend alors cette chaîne, pas son défaut : les deux cas comptent.
 */
it('déduit l\'URL de rappel d\'APP_URL quand rien ne la fixe', function (string $fournisseur, array $rappelPose): void {
    $configuration = ConfigurationDesServicesRelue::avec(['APP_URL' => 'https://gym.example.org/', ...$rappelPose]);

    expect(data_get($configuration, "{$fournisseur}.redirect"))->toBe("https://gym.example.org/auth/{$fournisseur}/callback");
})->with(['google', 'github', 'apple'])->with([
    'variable absente' => [[]],
    'variable vide, comme la transmet la composition' => [['GOOGLE_REDIRECT_URI' => '', 'GITHUB_REDIRECT_URI' => '', 'APPLE_REDIRECT_URI' => '']],
]);

it('garde l\'URL de rappel posée explicitement', function (): void {
    $configuration = ConfigurationDesServicesRelue::avec([
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
})->with(['google', 'github', 'apple']);

/*
 * La clé .p8 tient sur plusieurs lignes, une variable de la pile sur une
 * seule (#1911) : écrite avec des « \n » en toutes lettres, elle doit en
 * ressortir lisible par OpenSSL, sans quoi chaque échange échoue en
 * `invalid_client` alors que le bouton est affiché.
 */
it('relit la clé privée d\'Apple écrite sur une seule ligne', function (): void {
    $cle = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    expect($cle)->not->toBeFalse();
    assert($cle !== false);
    openssl_pkey_export($cle, $pem);
    assert(is_string($pem));

    $configuration = ConfigurationDesServicesRelue::avec([
        'APPLE_TEAM_ID' => 'EQUIPE0001',
        'APPLE_KEY_ID' => 'CLEAPP0001',
        'APPLE_PRIVATE_KEY' => str_replace("\n", '\n', trim($pem)),
    ]);
    $relue = data_get($configuration, 'apple.private_key');

    // Le paquet lit le trio sous ces noms-là, et nulle part ailleurs.
    expect(data_get($configuration, 'apple.team_id'))->toBe('EQUIPE0001')
        ->and(data_get($configuration, 'apple.key_id'))->toBe('CLEAPP0001')
        ->and($relue)->toBe(trim($pem))
        ->and(openssl_pkey_get_private(is_string($relue) ? $relue : ''))->not->toBeFalse();
});

it('garde la clé privée d\'Apple écrite sur plusieurs lignes, et tient une variable vide pour absente', function (): void {
    $surPlusieursLignes = "première ligne de la clé\ndeuxième ligne\ntroisième ligne";
    $equipeEtCle = ['APPLE_TEAM_ID' => 'EQUIPE0001', 'APPLE_KEY_ID' => 'CLEAPP0001'];

    expect(data_get(ConfigurationDesServicesRelue::avec([...$equipeEtCle, 'APPLE_PRIVATE_KEY' => $surPlusieursLignes]), 'apple.private_key'))->toBe($surPlusieursLignes)
        ->and(data_get(ConfigurationDesServicesRelue::avec([...$equipeEtCle, 'APPLE_PRIVATE_KEY' => '']), 'apple.private_key'))->toBeNull()
        ->and(data_get(ConfigurationDesServicesRelue::avec($equipeEtCle), 'apple.private_key'))->toBeNull();
});

/*
 * Le paquet signe un secret dès qu'il voit une clé privée, sans regarder les
 * deux autres pièces, et ignore alors le secret posé : sans équipe, l'échange
 * tombait en 500 (TypeError dans la signature) ; sans identifiant de clé, il
 * présentait à Apple un jeton sans `kid`, refusé en `invalid_client`. La clé
 * ne lui parvient donc qu'avec le trio complet ; incomplet, c'est le secret
 * posé qui part, comme le README le promet.
 */
it('ne transmet la clé privée d\'Apple qu\'avec l\'équipe et l\'identifiant de sa clé', function (array $variables): void {
    $configuration = ConfigurationDesServicesRelue::avec([
        'APPLE_TEAM_ID' => 'EQUIPE0001',
        'APPLE_KEY_ID' => 'CLEAPP0001',
        'APPLE_PRIVATE_KEY' => 'le contenu du .p8 de test',
        ...$variables,
    ]);

    expect(data_get($configuration, 'apple.private_key'))->toBeNull();
})->with([
    'sans équipe' => [['APPLE_TEAM_ID' => null]],
    'sans identifiant de clé' => [['APPLE_KEY_ID' => null]],
    'équipe vide, comme la transmet la composition' => [['APPLE_TEAM_ID' => '']],
    'identifiant de clé vide, comme le transmet la composition' => [['APPLE_KEY_ID' => '']],
    'identifiant de clé fait d\'espaces' => [['APPLE_KEY_ID' => '   ']],
]);
