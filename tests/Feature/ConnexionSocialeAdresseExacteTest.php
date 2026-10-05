<?php

declare(strict_types=1);

/*
 * Un retour de connexion sociale n'ouvre un compte existant que pour son
 * adresse : par l'identité qui l'a déjà ouvert quand l'adresse rendue est
 * encore celle du compte, ou par une adresse identique et garantie.
 *
 * La recherche par adresse passait par la base, dont la collation
 * (`utf8mb4_unicode_ci`) ignore accents et casse, replie « ß » sur « ss », le
 * signe kelvin sur « k » et la pleine chasse sur l'ASCII : une adresse proche,
 * vérifiée chez le fournisseur par quelqu'un d'autre, ouvrait le compte. Et
 * l'identité rendue par le fournisseur n'était jamais comparée.
 *
 * L'identité seule ne suffit pas : l'adresse du compte a pu changer depuis la
 * liaison, et l'ancienne recherche a pu poser des liaisons sur des adresses
 * seulement proches. Rien en base ne les distingue d'une liaison saine.
 *
 * Chaque cas passe par la vraie route de rappel, avec le retour que rend le
 * pilote de chaque fournisseur : Google (point d'information, `email_verified`
 * booléen), GitHub (identifiant entier, aucune clé de vérification, l'adresse
 * principale vérifiée ou null) et Apple (jeton d'identité, `email_verified` en
 * chaîne).
 */

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Models\User;
use App\Support\ConnexionSociale\FournisseurApple;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AppleSimule;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withUnencryptedCookie;

/**
 * Un fournisseur qui rend, à chaque appel, le retour suivant de la liste.
 */
function fournisseurSocialQuiRend(SocialiteUser ...$retours): Provider
{
    return new class(array_values($retours)) implements Provider
    {
        /**
         * @param  list<SocialiteUser>  $retours
         */
        public function __construct(private array $retours)
        {
        }

        public function redirect(): never
        {
            throw new LogicException('Ce test n’emprunte pas la redirection.');
        }

        public function user(): SocialiteUser
        {
            return array_shift($this->retours) ?? throw new LogicException('Aucun retour de plus n’était prévu.');
        }
    };
}

/**
 * L'identifiant qu'un fournisseur donne à la même personne, sous sa forme.
 */
function identiteSocialeDe(string $fournisseur, string $variante = 'principale'): string
{
    $suffixe = $variante === 'principale' ? '1' : '2';

    return match ($fournisseur) {
        'google' => '10987654321098765432'.$suffixe,
        'github' => '58323'.$suffixe,
        'apple' => '001234.0f1e2d3c4b5a69788796a5b4c3d2e1f0.123'.$suffixe,
        default => throw new InvalidArgumentException($fournisseur),
    };
}

/**
 * Le retour d'un fournisseur, construit comme son pilote le construit.
 *
 * Une adresse non vérifiée, pour GitHub, c'est une adresse absente : son
 * pilote ne rend que l'adresse principale vérifiée, ou rien.
 */
function retourSocialDe(string $fournisseur, string $identifiant, ?string $adresse, bool $verifiee = true): SocialiteUser
{
    $avatar = 'https://example.org/avatar-'.$fournisseur.'.jpg';

    return match ($fournisseur) {
        'google' => new SocialiteUser()
            ->setRaw([
                'sub' => $identifiant,
                'id' => $identifiant,
                'email' => $adresse,
                'email_verified' => $verifiee,
                'verified_email' => $verifiee,
                'name' => 'Camille Martin',
                'picture' => $avatar,
            ])
            ->map(['id' => $identifiant, 'nickname' => null, 'name' => 'Camille Martin', 'email' => $adresse, 'avatar' => $avatar]),
        'github' => new SocialiteUser()
            ->setRaw([
                'id' => (int) $identifiant,
                'node_id' => 'MDQ6VXNlcjU4MzIz',
                'login' => 'cmartin',
                'name' => 'Camille Martin',
                'email' => $verifiee ? $adresse : null,
                'avatar_url' => $avatar,
            ])
            ->map(['id' => (int) $identifiant, 'nickname' => 'cmartin', 'name' => 'Camille Martin', 'email' => $verifiee ? $adresse : null, 'avatar' => $avatar]),
        'apple' => new SocialiteUser()
            ->setRaw([
                'sub' => $identifiant,
                'email' => $adresse,
                'email_verified' => $verifiee ? 'true' : 'false',
                'is_private_email' => 'false',
            ])
            ->map(['id' => $identifiant, 'name' => null, 'email' => $adresse]),
        default => throw new InvalidArgumentException($fournisseur),
    };
}

function compteSocialExistant(string $adresse, ?string $fournisseur = null, ?string $identifiant = null): User
{
    return User::factory()->create([
        'email' => $adresse,
        'provider' => $fournisseur,
        'provider_id' => $identifiant,
        'avatar' => null,
    ]);
}

/**
 * La suite du seul refus qui ne sait pas si un compte existe : le retour n'a
 * pas d'adresse.
 */
function suiteDuRefusSocialSansCompte(): string
{
    return 'Connectez-vous avec votre email et votre mot de passe, ou inscrivez-vous.';
}

/**
 * La suite d'un refus quand un compte existe : jamais l'inscription.
 */
function suiteDuRefusSocialVersLeMotDePasse(string $quelleAdresse): string
{
    return 'Connectez-vous avec '.$quelleAdresse.' et votre mot de passe. Si vous n\'en avez pas, « Mot de passe oublié ? » vous permet d\'en choisir un.';
}

function refusSocialDAdresseProche(string $fournisseur): string
{
    return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte : un compte existe déjà sous une adresse que nous ne distinguons pas de la vôtre. S\'il est à vous, connectez-vous avec son adresse email et votre mot de passe ; sinon, inscrivez-vous avec une autre adresse.';
}

function refusSocialDAdresseHorsAscii(string $fournisseur): string
{
    return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte : la connexion avec '.ucfirst($fournisseur).' n\'accepte que les adresses en caractères ASCII. Inscrivez-vous avec cette adresse et un mot de passe.';
}

function refusSocialDIdentite(string $fournisseur): string
{
    return 'Ce compte '.ucfirst($fournisseur).' est associé à un compte dont l\'adresse email n\'est pas celle que '.ucfirst($fournisseur).' nous transmet. '.suiteDuRefusSocialVersLeMotDePasse('l\'adresse email de ce compte');
}

function refusSocialDeCompteDejaLie(string $fournisseur): string
{
    return 'Un compte existe déjà avec cette adresse email, associé à un autre compte '.ucfirst($fournisseur).'. '.suiteDuRefusSocialVersLeMotDePasse('cette adresse');
}

/**
 * Le refus d'un compte non vérifié, en français : la clé de traduction était
 * affichée telle quelle, en anglais, et sans issue.
 */
function refusSocialDeCompteNonVerifie(): string
{
    return 'Ce compte n\'a pas encore confirmé son adresse email : il ne peut pas être associé à un fournisseur de connexion. Connectez-vous avec cette adresse et votre mot de passe, puis confirmez-la. Si vous n\'avez pas de mot de passe, « Mot de passe oublié ? » vous permet d\'en choisir un.';
}

/**
 * Le refus a laissé une trace, une seule, avec ce message et ce contexte
 * exactement : le fournisseur et le compte, jamais l'adresse.
 *
 * @param  array<string, mixed>  $contexte
 */
function refusSocialJournalise(MockInterface $journal, string $message, array $contexte): void
{
    $journal->shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $messageEcrit, array $contexteEcrit): bool => $messageEcrit === $message && $contexteEcrit === $contexte);
}

/**
 * Le retour d'Apple par sa vraie route, en POST venu de son site : un départ
 * qui pose le cookie du nonce, puis le jeton d'identité qu'Apple signerait
 * pour ce nonce, avec cette adresse vérifiée.
 *
 * @return TestResponse<Response>
 */
function retourDAppleEnPostAvecLAdresse(AppleSimule $apple, string $adresse): TestResponse
{
    $depart = get(route('social.redirect', 'apple'));
    $nonce = AppleSimule::nonceEnvoyePar((string) $depart->headers->get('Location'));
    $cookieDuNonce = (string) $depart->getCookie(FournisseurApple::COOKIE_DU_NONCE, decrypt: false)?->getValue();

    $apple->repondraParLeJeton($apple->jetonDIdentite($nonce, ['email' => $adresse, 'email_verified' => 'true']));
    $apple->nouvelleRequete();

    return withUnencryptedCookie(FournisseurApple::COOKIE_DU_NONCE, $cookieDuNonce)->post(
        route('social.callback.apple'),
        ['code' => 'code-d-autorisation-de-test'],
        ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://appleid.apple.com'],
    );
}

/**
 * L'inscription par mot de passe avec cette adresse, telle que la page la poste.
 *
 * @return TestResponse<Response>
 */
function inscriptionSocialeAvecLAdresse(string $adresse): TestResponse
{
    return post(route('register'), [
        'name' => 'Camille Martin',
        'email' => $adresse,
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ]);
}

$fournisseurs = [
    'Google' => 'google',
    'GitHub' => 'github',
    'Apple' => 'apple',
];

it('refuse une adresse seulement proche de celle d’un compte, sans créer de doublon', function (string $fournisseur, string $adresseDuCompte, string $adresseRendue): void {
    $compte = compteSocialExistant($adresseDuCompte);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), $adresseRendue)),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDAdresseProche($fournisseur));

    assertGuest();

    // Ni rattaché, ni doublé : le compte reste seul et sans liaison.
    expect(User::query()->count())->toBe(1);
    expect($compte->refresh()->provider)->toBeNull();
    expect($compte->provider_id)->toBeNull();
})->with($fournisseurs)->with([
    'un accent' => ['jean.dupont@example.org', 'jéan.dupont@example.org'],
    'une majuscule accentuée' => ['jean.dupont@example.org', 'JÉAN.DUPONT@EXAMPLE.ORG'],
    'le signe kelvin, que mb_strtolower replie sur « k »' => ['kim@example.org', "\u{212A}im@example.org"],
    'le s long' => ['sam@example.org', "\u{17F}am@example.org"],
    '« ß » pour « ss »' => ['strasse@example.org', 'straße@example.org'],
    'un domaine internationalisé' => ['jean@bucher.example.org', 'jean@bücher.example.org'],
    'la pleine chasse' => ['jean@example.org', "\u{FF4A}ean@example.org"],
    'l’accent porté par le compte' => ['jéan@example.org', 'jean@example.org'],
]);

it('refuse une adresse non ASCII même quand aucun compte ne lui ressemble', function (string $fournisseur): void {
    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'jean@bücher.example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDAdresseHorsAscii($fournisseur));

    assertGuest();

    // Créé, ce compte aurait occupé l'adresse ASCII voisine aux yeux de l'index unique.
    expect(User::query()->count())->toBe(0);
})->with($fournisseurs);

it('rattache l’adresse identique et vérifiée, puis refuse l’identité quand l’adresse change chez le fournisseur', function (string $fournisseur): void {
    $compte = compteSocialExistant('camille.martin@example.org');
    $autreCompte = compteSocialExistant('camille@example.org');
    $identifiant = identiteSocialeDe($fournisseur);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(fournisseurSocialQuiRend(
        // La casse ASCII est le seul écart admis : l'index unique la confond déjà.
        retourSocialDe($fournisseur, $identifiant, 'Camille.Martin@EXAMPLE.org'),
        // Puis l'adresse change chez le fournisseur, pour celle d'un autre compte.
        retourSocialDe($fournisseur, $identifiant, 'camille@example.org'),
    ));

    get(route('social.callback', $fournisseur))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect($compte->refresh()->provider)->toBe($fournisseur);
    expect($compte->provider_id)->toBe($identifiant);

    auth()->guard('web')->logout();

    // Ni le compte de l'identité, ni celui de l'adresse : ni l'un ni l'autre n'est sûr.
    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();
    expect($autreCompte->refresh()->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(2);
})->with($fournisseurs);

it('refuse une identité déjà liée quand l’adresse a changé chez le fournisseur, sans créer de compte', function (string $fournisseur): void {
    /*
     * La liaison peut être saine, et l'adresse avoir changé chez le
     * fournisseur ; rien en base ne permet de le savoir. Le titulaire passe
     * par le mot de passe du compte, comme le message le lui dit.
     */
    $identifiant = identiteSocialeDe($fournisseur);
    $compte = compteSocialExistant('ancienne@example.org', $fournisseur, $identifiant);
    $journal = Log::spy();

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, $identifiant, 'nouvelle@example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();
    expect(User::query()->count())->toBe(1);
    expect($compte->refresh()->email)->toBe('ancienne@example.org');
    expect($compte->provider_id)->toBe($identifiant);
    refusSocialJournalise($journal, 'Connexion sociale refusée : l’identité rend une autre adresse que celle de son compte', [
        'fournisseur' => $fournisseur,
        'comptes' => [$compte->id],
    ]);
})->with($fournisseurs);

it('n’ouvre plus le compte d’une identité dont l’adresse a changé depuis la liaison, réinitialisation comprise', function (string $fournisseur): void {
    /*
     * Le compte naît par le fournisseur, puis son adresse devient celle d'une
     * autre personne, depuis le profil. Cette personne ne peut ni s'inscrire
     * (l'adresse est prise) ni passer par un fournisseur (le compte n'est pas
     * vérifié) : elle reprend le compte par le lien de réinitialisation, puis
     * le vérifie. L'identité qui a ouvert le compte ne doit plus l'ouvrir.
     */
    Notification::fake();
    $identifiant = identiteSocialeDe($fournisseur);
    $retourDeLIdentite = retourSocialDe($fournisseur, $identifiant, 'camille.martin@example.org');

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(fournisseurSocialQuiRend($retourDeLIdentite, $retourDeLIdentite));

    get(route('social.callback', $fournisseur))->assertRedirect(route('dashboard'));
    $compte = User::query()->sole();

    // Un mot de passe connu : le profil peut l'exiger pour changer d'adresse.
    $compte->forceFill(['password' => 'Mot-de-passe-du-profil-42!'])->save();

    patch(route('profile.update'), [
        'name' => 'Dominique Petit',
        'email' => 'dominique.petit@example.org',
        'current_password' => 'Mot-de-passe-du-profil-42!',
    ])->assertSessionHasNoErrors();

    expect($compte->refresh()->email)->toBe('dominique.petit@example.org');
    expect($compte->hasVerifiedEmail())->toBeFalse();
    auth()->guard('web')->logout();

    post(route('password.store'), [
        'token' => Password::createToken($compte),
        'email' => 'dominique.petit@example.org',
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ])->assertSessionHasNoErrors();

    $compte->refresh()->markEmailAsVerified();
    auth()->guard('web')->logout();

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();
    expect(User::query()->count())->toBe(1);
})->with($fournisseurs);

it('ne rattache jamais un compte déjà lié à une autre identité du même fournisseur', function (string $fournisseur): void {
    $compte = compteSocialExistant('camille.martin@example.org', $fournisseur, identiteSocialeDe($fournisseur, 'autre'));
    $journal = Log::spy();

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'camille.martin@example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDeCompteDejaLie($fournisseur));

    assertGuest();
    expect($compte->refresh()->provider_id)->toBe(identiteSocialeDe($fournisseur, 'autre'));
    expect(User::query()->count())->toBe(1);
    refusSocialJournalise($journal, 'Connexion sociale refusée : compte lié à une autre identité du même fournisseur', [
        'fournisseur' => $fournisseur,
        'compte' => $compte->id,
    ]);
})->with($fournisseurs);

it('journalise le refus d’une adresse seulement proche de celle d’un compte, sans l’adresse', function (string $fournisseur): void {
    /*
     * L'adresse rendue est en ASCII et passe donc le premier filtre : c'est
     * le compte trouvé par la base qui porte l'accent.
     */
    $compte = compteSocialExistant('jéan.dupont@example.org');
    $journal = Log::spy();

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'jean.dupont@example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDAdresseProche($fournisseur));

    assertGuest();
    expect($compte->refresh()->provider_id)->toBeNull();
    refusSocialJournalise($journal, 'Connexion sociale refusée : adresse seulement proche de celle d’un compte', [
        'fournisseur' => $fournisseur,
        'compte' => $compte->id,
    ]);
})->with($fournisseurs);

it('n’ouvre pas le compte auquel une identité a été liée sur une adresse seulement proche', function (string $fournisseur, string $adresseDuCompte, string $adresseRendue): void {
    /*
     * L'état qu'a pu laisser la recherche par adresse d'avant la comparaison
     * exacte : l'identité liée au compte que la collation confondait avec
     * l'adresse rendue. Rien ne distingue cette liaison d'une autre : c'est
     * l'adresse du compte qui ouvre, quelle que soit celle que rend
     * l'identité, proche ou sans rapport.
     */
    $identifiant = identiteSocialeDe($fournisseur);
    $compte = compteSocialExistant($adresseDuCompte, $fournisseur, $identifiant);
    $journal = Log::spy();

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, $identifiant, $adresseRendue)),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();
    expect(User::query()->count())->toBe(1);
    expect($compte->refresh()->provider_id)->toBe($identifiant);
    refusSocialJournalise($journal, 'Connexion sociale refusée : l’identité rend une autre adresse que celle de son compte', [
        'fournisseur' => $fournisseur,
        'comptes' => [$compte->id],
    ]);
})->with($fournisseurs)->with([
    'un accent' => ['jean.dupont@example.org', 'jéan.dupont@example.org'],
    'l’accent porté par le compte' => ['jéan.dupont@example.org', 'jean.dupont@example.org'],
    'deux accents différents' => ['jéan.dupont@example.org', 'jèan.dupont@example.org'],
    'la pleine chasse' => ['jean.dupont@example.org', "\u{FF4A}ean.dupont@example.org"],
    'une espace finale' => ['jean.dupont@example.org', 'jean.dupont@example.org '],
    'une adresse sans rapport' => ['jean.dupont@example.org', 'dominique.petit@example.net'],
    'une adresse relais' => ['jean.dupont@example.org', 'x7k2p9q4rs@privaterelay.appleid.com'],
]);

it('n’ouvre pas le compte d’une liaison posée sur une adresse seulement proche quand l’adresse change ensuite', function (string $fournisseur, string $adresseSansRapport): void {
    $identifiant = identiteSocialeDe($fournisseur);
    $compte = compteSocialExistant('jean.dupont@example.org', $fournisseur, $identifiant);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(fournisseurSocialQuiRend(
        retourSocialDe($fournisseur, $identifiant, 'jéan.dupont@example.org'),
        retourSocialDe($fournisseur, $identifiant, $adresseSansRapport),
    ));

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDIdentite($fournisseur));

    assertGuest();
    expect(User::query()->count())->toBe(1);
    expect($compte->refresh()->email)->toBe('jean.dupont@example.org');
})->with([
    'Google, une autre adresse' => ['google', 'dominique.petit@example.net'],
    'GitHub, une autre adresse principale' => ['github', 'dominique.petit@example.net'],
    'Apple, une adresse relais' => ['apple', 'x7k2p9q4rs@privaterelay.appleid.com'],
]);

it('ouvre le compte de l’identité liée quand l’adresse est la même, à la casse ASCII près', function (string $fournisseur, string $adresseDuCompte, string $adresseRendue): void {
    $identifiant = identiteSocialeDe($fournisseur);
    $compte = compteSocialExistant($adresseDuCompte, $fournisseur, $identifiant);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, $identifiant, $adresseRendue)),
    );

    get(route('social.callback', $fournisseur))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
})->with($fournisseurs)->with([
    'la même adresse, hors ASCII' => ['jéan.dupont@example.org', 'jéan.dupont@example.org'],
    'une autre casse ASCII' => ['camille.martin@example.org', 'Camille.Martin@EXAMPLE.org'],
]);

it('ne rattache pas un compte qui n’a pas vérifié son adresse', function (string $fournisseur): void {
    /*
     * Quiconque peut s'inscrire par mot de passe avec l'adresse d'un autre :
     * rattacher ce compte au retour du vrai titulaire lui laisserait la porte
     * du mot de passe.
     */
    $compte = User::factory()->unverified()->create([
        'email' => 'camille.martin@example.org',
        'provider' => null,
        'provider_id' => null,
    ]);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'camille.martin@example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDeCompteNonVerifie());

    assertGuest();
    expect($compte->refresh()->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(1);
})->with($fournisseurs);

it('refuse un retour GitHub dont l’adresse est vide', function (): void {
    Socialite::shouldReceive('driver')->with('github')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('github', identiteSocialeDe('github'), '')),
    );

    get(route('social.callback', 'github'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Votre email n\'est pas vérifié par Github');

    assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('refuse au rappel POST d’Apple une adresse seulement proche, et rattache l’adresse identique', function (?string $identiteDejaLiee, string $refus): void {
    $apple = new AppleSimule();
    $apple->configurer();

    // Le `sub` que signe le jeton d'identité d'AppleSimule.
    $identifiantApple = '001234.apple-de-test.0042';
    $compte = compteSocialExistant('jean.dupont@example.org', $identiteDejaLiee === null ? null : 'apple', $identiteDejaLiee);

    retourDAppleEnPostAvecLAdresse($apple, 'jéan.dupont@example.org')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', $refus);

    assertGuest();
    expect($compte->refresh()->provider_id)->toBe($identiteDejaLiee);
    expect(User::query()->count())->toBe(1);

    retourDAppleEnPostAvecLAdresse($apple, 'jean.dupont@example.org')->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect($compte->refresh()->provider)->toBe('apple');
    expect($compte->provider_id)->toBe($identifiantApple);
    expect($apple->requetesParties())->toHaveCount(2);
})->with([
    'par l’adresse' => [null, refusSocialDAdresseProche('apple')],
    'par une identité liée auparavant' => ['001234.apple-de-test.0042', refusSocialDIdentite('apple')],
]);

it('ne rattache pas une adresse que le fournisseur ne garantit pas, en production comme en local', function (string $fournisseur, string $refusEnLocal): void {
    $compte = compteSocialExistant('camille.martin@example.org');
    $retourNonVerifie = retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'camille.martin@example.org', verifiee: false);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(fournisseurSocialQuiRend($retourNonVerifie, $retourNonVerifie));

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Votre email n\'est pas vérifié par '.ucfirst($fournisseur));

    assertGuest();

    /*
     * En local, le contrôle du rappel laisse passer une adresse non vérifiée
     * pour créer un compte de développement. Il ne doit pas pour autant en
     * rattacher un existant : la liaison ne dépend pas de l'environnement.
     */
    app()->detectEnvironment(fn (): string => 'local');

    expect(fn () => app(HandleSocialCallbackAction::class)->execute($fournisseur))
        ->toThrow(new SocialAuthException($refusEnLocal));

    expect($compte->refresh()->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(1);
})->with([
    'Google' => ['google', 'Votre email n\'est pas vérifié par Google'],
    'GitHub, dont le pilote ne rend pas d’adresse non vérifiée' => ['github', 'Github ne nous a transmis aucune adresse email. '.suiteDuRefusSocialSansCompte()],
    'Apple' => ['apple', 'Votre email n\'est pas vérifié par Apple'],
]);

it('accepte l’adresse que le pilote GitHub rend, parce qu’il ne rend que la principale vérifiée', function (): void {
    /*
     * La garantie tient à la portée `user:email` : sans elle, le pilote
     * rendrait l'adresse publique du profil au lieu de la principale vérifiée.
     */
    $pilote = Socialite::driver('github');

    if (! $pilote instanceof GithubProvider) {
        throw new LogicException('Le pilote github n’est plus celui de Socialite.');
    }

    expect($pilote->getScopes())->toContain('user:email');

    Socialite::shouldReceive('driver')->with('github')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('github', identiteSocialeDe('github'), 'camille.martin@example.org')),
    );

    get(route('social.callback', 'github'))->assertRedirect(route('dashboard'));

    $compte = User::query()->sole();

    assertAuthenticatedAs($compte);
    expect($compte->provider)->toBe('github');
    expect($compte->provider_id)->toBe(identiteSocialeDe('github'));
    expect($compte->hasVerifiedEmail())->toBeTrue();
});

it('cherche l’identité et l’adresse par paramètres liés, jamais dans le texte de la requête', function (): void {
    compteSocialExistant('camille.martin@example.org');
    $identifiant = identiteSocialeDe('google');

    Socialite::shouldReceive('driver')->with('google')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('google', $identifiant, 'camille.martin@example.org')),
    );

    /** @var list<QueryExecuted> $requetes */
    $requetes = [];
    DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete;
    });

    get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

    $rechercheDeLIdentite = array_values(array_filter(
        $requetes,
        static fn (QueryExecuted $requete): bool => str_contains($requete->sql, '`provider_id` = ?')
            && str_starts_with($requete->sql, 'select'),
    ));

    expect($rechercheDeLIdentite)->toHaveCount(1);
    expect($rechercheDeLIdentite[0]->bindings)->toBe(['google', $identifiant]);

    $textesDesRequetes = implode("\n", array_map(static fn (QueryExecuted $requete): string => $requete->sql, $requetes));

    expect($textesDesRequetes)->not->toContain('camille.martin@example.org');
    expect($textesDesRequetes)->not->toContain($identifiant);
});

/*
 * Chaque refus propose une issue, et cette issue existe : un message qui
 * renvoie à l'inscription quand un compte occupe l'adresse laisse le titulaire
 * légitime sans issue, l'index unique de la même collation refusant
 * l'inscription.
 */

it('ne propose pas l’inscription avec une adresse qu’un compte occupe déjà', function (string $adresseDuCompte, string $adresseRendue): void {
    compteSocialExistant($adresseDuCompte);

    Socialite::shouldReceive('driver')->with('google')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('google', identiteSocialeDe('google'), $adresseRendue)),
    );

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDAdresseProche('google'));

    // Ce que le message ne propose pas, parce que la base le refuse.
    inscriptionSocialeAvecLAdresse($adresseRendue)->assertSessionHasErrors('email');
    assertGuest();
    expect(User::query()->count())->toBe(1);
})->with([
    'l’adresse rendue en ASCII, le compte accentué' => ['jéan.dupont@example.org', 'jean.dupont@example.org'],
    'l’adresse rendue accentuée, le compte en ASCII' => ['jean.dupont@example.org', 'jéan.dupont@example.org'],
]);

it('propose l’inscription avec une adresse hors ASCII qu’aucun compte n’occupe, et elle aboutit', function (): void {
    Socialite::shouldReceive('driver')->with('google')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('google', identiteSocialeDe('google'), 'jéan.dupont@example.org')),
    );

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDAdresseHorsAscii('google'));

    assertGuest();

    inscriptionSocialeAvecLAdresse('jéan.dupont@example.org')->assertSessionHasNoErrors();

    expect(User::query()->sole()->email)->toBe('jéan.dupont@example.org');
});

it('renvoie au mot de passe oublié un compte lié à une autre identité, et le lien part à son adresse', function (): void {
    Notification::fake();
    $compte = compteSocialExistant('camille.martin@example.org', 'google', identiteSocialeDe('google', 'autre'));

    Socialite::shouldReceive('driver')->with('google')->andReturn(
        fournisseurSocialQuiRend(retourSocialDe('google', identiteSocialeDe('google'), 'camille.martin@example.org')),
    );

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', refusSocialDeCompteDejaLie('google'));

    // L'inscription, que le message ne propose plus, est refusée…
    inscriptionSocialeAvecLAdresse('camille.martin@example.org')->assertSessionHasErrors('email');

    // … et le mot de passe oublié, qu'il propose, aboutit.
    post(route('password.email'), ['email' => 'camille.martin@example.org'])->assertSessionHasNoErrors();

    Notification::assertSentTo($compte, ResetPassword::class);
    assertGuest();
});
