<?php

declare(strict_types=1);

/*
 * Un retour de connexion sociale n'ouvre un compte existant que par l'identité
 * qui l'a déjà ouvert, ou par une adresse identique et garantie.
 *
 * La recherche par adresse passait par la base, dont la collation
 * (`utf8mb4_unicode_ci`) ignore accents et casse, replie « ß » sur « ss », le
 * signe kelvin sur « k » et la pleine chasse sur l'ASCII : une adresse proche,
 * vérifiée chez le fournisseur par quelqu'un d'autre, ouvrait le compte. Et
 * l'identité rendue par le fournisseur n'était jamais comparée.
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;

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

function suiteDuRefusSocial(): string
{
    return 'Connectez-vous avec votre email et votre mot de passe, ou inscrivez-vous.';
}

function refusDAdresseSociale(string $fournisseur): string
{
    return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas être associée automatiquement à un compte. '.suiteDuRefusSocial();
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
        ->assertSessionHas('status', refusDAdresseSociale($fournisseur));

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
        ->assertSessionHas('status', refusDAdresseSociale($fournisseur));

    assertGuest();

    // Créé, ce compte aurait occupé l'adresse ASCII voisine aux yeux de l'index unique.
    expect(User::query()->count())->toBe(0);
})->with($fournisseurs);

it('rattache l’adresse identique et vérifiée, puis reconnaît l’identité quand l’adresse change', function (string $fournisseur): void {
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

    get(route('social.callback', $fournisseur))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect($autreCompte->refresh()->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(2);
})->with($fournisseurs);

it('reconnaît une identité déjà liée même quand l’adresse a changé chez le fournisseur', function (string $fournisseur): void {
    $identifiant = identiteSocialeDe($fournisseur);
    $compte = compteSocialExistant('ancienne@example.org', $fournisseur, $identifiant);

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, $identifiant, 'nouvelle@example.org')),
    );

    get(route('social.callback', $fournisseur))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect(User::query()->count())->toBe(1);
    expect($compte->refresh()->email)->toBe('ancienne@example.org');
})->with($fournisseurs);

it('ne rattache jamais un compte déjà lié à une autre identité du même fournisseur', function (string $fournisseur): void {
    $compte = compteSocialExistant('camille.martin@example.org', $fournisseur, identiteSocialeDe($fournisseur, 'autre'));

    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(
        fournisseurSocialQuiRend(retourSocialDe($fournisseur, identiteSocialeDe($fournisseur), 'camille.martin@example.org')),
    );

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Ce compte est déjà associé à un autre compte '.ucfirst($fournisseur).'. '.suiteDuRefusSocial());

    assertGuest();
    expect($compte->refresh()->provider_id)->toBe(identiteSocialeDe($fournisseur, 'autre'));
    expect(User::query()->count())->toBe(1);
})->with($fournisseurs);

it('ne rattache pas une adresse que le fournisseur ne garantit pas, en production comme en local', function (string $fournisseur): void {
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
        ->toThrow(SocialAuthException::class);

    expect($compte->refresh()->provider_id)->toBeNull();
    expect(User::query()->count())->toBe(1);
})->with($fournisseurs);

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
