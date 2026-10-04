<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ConnexionSociale\FournisseurApple;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256 as Es256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AppleSimule;
use Tests\Support\ConfigurationDesServicesRelue;

use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withUnencryptedCookie;

/*
 * « Continuer avec Apple » ne pouvait pas aboutir, même configuré (#1911).
 *
 * Apple renvoie l'utilisateur par un POST depuis son site : la route n'acceptait
 * que GET (405), la protection CSRF refusait ce POST sans jeton (419), et le
 * cookie de session, en `SameSite=lax`, ne voyageait pas avec lui — l'état
 * rangé en session au départ était perdu. Rien ne signait non plus le secret
 * client. Ces tests jouent le trajet entier, Apple simulé par `AppleSimule`
 * avec des clés tirées pour l'occasion, la protection CSRF réglée comme en
 * production.
 */

/**
 * PreventRequestForgery s'efface sous les tests (`runningUnitTests()`) : cette
 * sous-classe ne s'efface pas, pour que le POST d'Apple affronte la vraie
 * décision, comme dans RequestForgeryProtectionTest.
 */
function connexionAppleProtegerCommeEnProduction(): void
{
    app()->bind(
        PreventRequestForgery::class,
        fn (Application $application): PreventRequestForgery => new class($application, $application->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        },
    );
}

/**
 * Le départ vers Apple : le nonce envoyé, et le cookie qui le garde chez le
 * navigateur, tel que celui-ci le rapportera.
 *
 * @return array{nonce: string, cookie: string}
 */
function connexionAppleDepart(): array
{
    $depart = get(route('social.redirect', 'apple'));

    return [
        'nonce' => AppleSimule::nonceEnvoyePar((string) $depart->headers->get('Location')),
        'cookie' => (string) $depart->getCookie(FournisseurApple::COOKIE_DU_NONCE, decrypt: false)?->getValue(),
    ];
}

/**
 * Le retour d'Apple : un POST venu de son site, sans jeton CSRF ni cookie de
 * session, qui rapporte (ou non) le cookie du nonce. Apple aura signé
 * `$nonceSigne` dans le jeton d'identité que l'échange rendra.
 *
 * @return TestResponse<Response>
 */
function connexionAppleRetour(AppleSimule $apple, ?string $cookieDuNonce, string $nonceSigne): TestResponse
{
    $apple->repondraParLeJeton($apple->jetonDIdentite($nonceSigne));
    $apple->nouvelleRequete();

    $formulaire = ['code' => 'code-d-autorisation-de-test', 'user' => '{"name":{"firstName":"Alex","lastName":"Martin"}}'];
    $enTetes = ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://appleid.apple.com'];

    if ($cookieDuNonce === null) {
        return post(route('social.callback.apple'), $formulaire, $enTetes);
    }

    return withUnencryptedCookie(FournisseurApple::COOKIE_DU_NONCE, $cookieDuNonce)
        ->post(route('social.callback.apple'), $formulaire, $enTetes);
}

it('part chez Apple sans état en session, le nonce dans un cookie chiffré qui survit au POST', function (): void {
    new AppleSimule()->configurer();

    $depart = get(route('social.redirect', 'apple'));
    $adresse = (string) $depart->headers->get('Location');
    parse_str((string) parse_url($adresse, PHP_URL_QUERY), $parametres);

    expect($adresse)->toStartWith('https://appleid.apple.com/auth/authorize?')
        ->and($parametres['response_mode'] ?? null)->toBe('form_post')
        ->and($parametres['client_id'] ?? null)->toBe(AppleSimule::SERVICES_ID)
        ->and($parametres)->not->toHaveKey('state');

    $cookie = $depart->getCookie(FournisseurApple::COOKIE_DU_NONCE, decrypt: false);

    // SameSite=None : le seul réglage qu'un POST venu d'Apple rapporte.
    expect($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('none');

    // Chiffré par le paquet, une seule fois : ce que le navigateur garde ne se
    // lit ni ne se forge, et ne pèse pas deux fois dans les en-têtes.
    expect(Crypt::decryptString((string) $cookie?->getValue()))->toBe(AppleSimule::nonceEnvoyePar($adresse))
        ->and(session()->has('state'))->toBeFalse()
        ->and(session()->has('nonce'))->toBeFalse();
});

it('connecte au retour d’Apple, en POST venu de son site, sans jeton CSRF ni cookie de session', function (): void {
    connexionAppleProtegerCommeEnProduction();
    $apple = new AppleSimule();
    $apple->configurer();

    ['nonce' => $nonce, 'cookie' => $cookie] = connexionAppleDepart();

    connexionAppleRetour($apple, $cookie, $nonce)->assertRedirect(route('dashboard'));

    assertAuthenticated();
    assertDatabaseHas('users', [
        'email' => 'nouveau@example.org',
        'name' => 'Alex Martin',
        'provider' => 'apple',
        'provider_id' => '001234.apple-de-test.0042',
    ]);

    $requetes = $apple->requetesParties();
    parse_str((string) ($requetes[0] ?? null)?->getBody(), $champs);

    expect($requetes)->toHaveCount(1)
        ->and((string) $requetes[0]->getUri())->toBe('https://appleid.apple.com/auth/token')
        ->and($champs['grant_type'] ?? null)->toBe('authorization_code')
        ->and($champs['code'] ?? null)->toBe('code-d-autorisation-de-test');
});

it('signe chaque échange d’un secret client neuf, avec la clé .p8 de l’application', function (): void {
    $apple = new AppleSimule();
    $apple->configurer();

    ['nonce' => $nonce, 'cookie' => $cookie] = connexionAppleDepart();
    connexionAppleRetour($apple, $cookie, $nonce)->assertRedirect(route('dashboard'));

    $requetes = $apple->requetesParties();
    expect($requetes)->toHaveCount(1);

    $secret = $apple->secretClientPresente($requetes[0]);
    $verification = Configuration::forAsymmetricSigner(
        new Es256(),
        InMemory::plainText($apple->clePriveeDeLApplication),
        InMemory::plainText($apple->clePubliqueDeLApplication),
    );
    $jeton = $verification->parser()->parse($secret);
    expect($jeton)->toBeInstanceOf(UnencryptedToken::class);
    assert($jeton instanceof UnencryptedToken);

    // Une autre clé ne le vérifie pas : c'est bien la clé de l'application qui signe.
    $autreCle = new AppleSimule();

    expect($verification->validator()->validate($jeton, new SignedWith(new Es256(), InMemory::plainText($apple->clePubliqueDeLApplication))))->toBeTrue()
        ->and($verification->validator()->validate($jeton, new SignedWith(new Es256(), InMemory::plainText($autreCle->clePubliqueDeLApplication))))->toBeFalse()
        ->and($jeton->headers()->get('alg'))->toBe('ES256')
        ->and($jeton->headers()->get('kid'))->toBe(AppleSimule::IDENTIFIANT_DE_CLE)
        ->and($jeton->claims()->get('iss'))->toBe(AppleSimule::EQUIPE)
        ->and($jeton->claims()->get('sub'))->toBe(AppleSimule::SERVICES_ID)
        ->and($jeton->claims()->get('aud'))->toBe(['https://appleid.apple.com']);

    $emisA = $jeton->claims()->get('iat');
    $expireA = $jeton->claims()->get('exp');
    assert($emisA instanceof DateTimeImmutable && $expireA instanceof DateTimeImmutable);

    // Signé au moment de l'échange, pour une heure : rien à refaire tous les six mois.
    expect(abs($emisA->getTimestamp() - time()))->toBeLessThan(60)
        ->and($expireA->getTimestamp() - $emisA->getTimestamp())->toBe(3600);
});

it('présente tel quel un secret client déjà signé, faute de trio', function (): void {
    $apple = new AppleSimule();
    $apple->configurer();
    config([
        'services.apple.team_id' => null,
        'services.apple.key_id' => null,
        'services.apple.private_key' => null,
        'services.apple.client_secret' => 'secret-signe-a-la-main',
    ]);

    ['nonce' => $nonce, 'cookie' => $cookie] = connexionAppleDepart();
    connexionAppleRetour($apple, $cookie, $nonce)->assertRedirect(route('dashboard'));

    expect($apple->secretClientPresente($apple->requetesParties()[0]))->toBe('secret-signe-a-la-main');
});

/*
 * L'exploitant qui passe du secret signé à la main au trio, une variable après
 * l'autre : la clé posée avant l'équipe ou l'identifiant de clé. Le paquet
 * signait dès qu'il voyait la clé, en ignorant le secret posé : sans équipe,
 * le retour répondait 500 ; sans identifiant de clé, Apple recevait un jeton
 * sans `kid` et refusait l'échange. Le bouton, lui, restait affiché.
 */
it('présente tel quel le secret posé tant que le trio est incomplet', function (array $variablesRetirees): void {
    $apple = new AppleSimule();
    $apple->configurer();

    $relue = ConfigurationDesServicesRelue::avec([
        'APPLE_CLIENT_ID' => AppleSimule::SERVICES_ID,
        'APPLE_CLIENT_SECRET' => 'secret-signe-a-la-main',
        'APPLE_TEAM_ID' => AppleSimule::EQUIPE,
        'APPLE_KEY_ID' => AppleSimule::IDENTIFIANT_DE_CLE,
        'APPLE_PRIVATE_KEY' => str_replace("\n", '\n', $apple->clePriveeDeLApplication),
        ...$variablesRetirees,
    ]);

    foreach (['client_id', 'client_secret', 'team_id', 'key_id', 'private_key'] as $cle) {
        config(["services.apple.{$cle}" => data_get($relue, "apple.{$cle}")]);
    }

    get(route('login'))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('social_login_enabled.apple', true));

    ['nonce' => $nonce, 'cookie' => $cookie] = connexionAppleDepart();
    connexionAppleRetour($apple, $cookie, $nonce)->assertRedirect(route('dashboard'));

    expect($apple->secretClientPresente($apple->requetesParties()[0]))->toBe('secret-signe-a-la-main');
})->with([
    'sans équipe' => [['APPLE_TEAM_ID' => null]],
    'sans identifiant de clé' => [['APPLE_KEY_ID' => null]],
    'équipe vide, comme la transmet la composition' => [['APPLE_TEAM_ID' => '']],
]);

it('refuse un retour sans cookie de nonce, sans même appeler Apple', function (): void {
    connexionAppleProtegerCommeEnProduction();
    $apple = new AppleSimule();
    $apple->configurer();

    ['nonce' => $nonce] = connexionAppleDepart();

    connexionAppleRetour($apple, null, $nonce)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Erreur lors de la connexion avec Apple');

    assertGuest();
    assertDatabaseMissing('users', ['email' => 'nouveau@example.org']);
    expect($apple->requetesParties())->toBe([]);
});

/*
 * Le rappel qu'un attaquant ferait jouer au navigateur de sa victime : le code
 * de son propre compte, dont Apple a signé son propre nonce, contre le cookie
 * du départ de la victime. Ou un cookie qu'il aurait écrit lui-même, nonce en
 * clair compris : seul le chiffrement de l'application fait foi.
 */
it('refuse un retour dont le nonce ne correspond pas au cookie', function (Closure $retour): void {
    connexionAppleProtegerCommeEnProduction();
    $apple = new AppleSimule();
    $apple->configurer();
    User::factory()->create(['email' => 'nouveau@example.org']);

    ['nonce' => $nonce, 'cookie' => $cookie] = connexionAppleDepart();

    /** @var array{0: string, 1: string} $rapporte */
    $rapporte = $retour($nonce, $cookie);

    connexionAppleRetour($apple, $rapporte[0], $rapporte[1])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Erreur lors de la connexion avec Apple');

    assertGuest();
})->with([
    'le nonce d’un autre départ' => [fn (string $nonce, string $cookie): array => [$cookie, 'nonce-du-depart-de-l-attaquant']],
    'un cookie forgé' => [fn (string $nonce, string $cookie): array => ['pas-un-chiffre-de-l-application', $nonce]],
    'le nonce en clair' => [fn (string $nonce, string $cookie): array => [$nonce, $nonce]],
]);

it('n’ouvre le rappel en POST qu’à Apple', function (string $fournisseur): void {
    connexionAppleProtegerCommeEnProduction();

    post("/auth/{$fournisseur}/callback", ['code' => 'code-d-autorisation-de-test'], ['Sec-Fetch-Site' => 'cross-site'])
        ->assertStatus(405);

    assertGuest();
})->with(['google', 'github']);
