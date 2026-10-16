<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use App\Support\LiensDesCourriels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\DureeSimulee;
use Tests\Support\FormulaireDeReinitialisation;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Le formulaire du lien « mot de passe oublié » demande l'adresse du compte
 * (décision du propriétaire du 2026-10-16, #2070).
 *
 * La page d'un lien qui vaut ne la donne pas (`OuvertureDuLienDeReinitialisationTest`) :
 * la personne, ou son gestionnaire de mots de passe, la saisit dans un champ
 * vide (`autocomplete="username"`), envoyé avec le nouveau mot de passe. Le
 * serveur ne réinitialise que si elle désigne le compte du lien, comme la
 * connexion le chercherait : selon la collation de l'index, sans compter la
 * casse ni les espaces autour. Qui tient un lien sans connaître l'adresse ne
 * peut donc ni changer le mot de passe ni apprendre l'identifiant de la
 * connexion. Une adresse qui ne désigne pas le compte reçoit le refus d'un
 * jeton faux, réponse entière et durée comprises, et le jeton reste ; une
 * adresse vide ou mal formée, l'erreur de validation du champ.
 */

/**
 * L'adresse du compte de chaque cas.
 */
function identifiantSaisiAdresse(): string
{
    return 'titulaire.saisie@example.org';
}

function identifiantSaisiMotDePasse(): string
{
    return 'Un-nouveau-mot-de-passe-2026!';
}

/**
 * Le lien du courriel pour ce compte, avec un jeton neuf du courtier ou celui
 * donné.
 */
function identifiantSaisiLienPour(User $compte, ?string $jeton = null): string
{
    return LiensDesCourriels::lienDeReinitialisation($compte, $jeton ?? Password::createToken($compte));
}

/**
 * Envoie le formulaire comme la page, depuis la page du lien : en JSON, avec
 * les en-têtes d'une visite d'Inertia (`.ai/rules/tests.md`).
 *
 * @param  array<string, mixed>  $donnees
 * @return TestResponse<Response>
 */
function identifiantSaisiEnvoyer(string $lien, array $donnees): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from($lien)->json('POST', route('password.store'), $donnees, [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ]);

    return $reponse;
}

/**
 * Tout ce que le client lit d'un envoi, comme s'il était le premier de cet
 * appareil (`CeQueLeClientLit`), page du lien rouverte comprise.
 *
 * La saisie que la session garde pour la page suivante (`_old_input`) en est
 * ôtée : elle recopie ce que chaque cas envoie, qui diffère par construction
 * (le jeton, l'adresse saisie), et ne part pas au navigateur, Inertia ne la
 * partageant pas.
 *
 * @param  array<string, mixed>  $donnees
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function identifiantSaisiCeQueLeClientLit(string $lien, array $donnees): array
{
    test()->flushSession();
    Cache::flush();

    $lu = CeQueLeClientLit::de(identifiantSaisiEnvoyer($lien, $donnees), $lien);
    unset($lu['session']['_old_input']);

    return $lu;
}

/**
 * Les erreurs de validation que la session garde pour la page suivante.
 *
 * @return array<mixed>
 */
function identifiantSaisiErreurs(): array
{
    $erreurs = session('errors');

    return $erreurs instanceof ViewErrorBag ? $erreurs->getBag('default')->toArray() : [];
}

/**
 * Le coût bcrypt que `.env.example` donne à la production, et que phpunit.xml
 * baisse à 4.
 */
function identifiantSaisiCoutDeProduction(): int
{
    if (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', File::get(base_path('.env.example')), $cout) !== 1) {
        throw new RuntimeException('.env.example ne donne plus BCRYPT_ROUNDS.');
    }

    return (int) $cout[1];
}

dataset('adresses saisies qui désignent le compte du lien', [
    'telle que le courriel l’a reçue' => [static fn (string $adresse): string => $adresse],
    'en capitales' => [strtoupper(...)],
    'casse mêlée, entre des espaces' => [static fn (string $adresse): string => '  '.ucwords($adresse, '.@').' '],
]);

it('réinitialise avec l’adresse que la personne connaît, quelles qu’en soient la casse et les espaces autour, et la connexion suit', function (Closure $saisir): void {
    $compte = User::factory()->create(['email' => identifiantSaisiAdresse()]);
    $lien = identifiantSaisiLienPour($compte);
    /** @var array<string, mixed> $proprietes */
    $proprietes = get($lien)->assertOk()->inertiaProps();
    /** @var string $saisie */
    $saisie = $saisir(identifiantSaisiAdresse());

    /** @var array<string, mixed> $lienDeLaPage */
    $lienDeLaPage = Arr::only($proprietes, ['token', 'adresse']);

    // La page ne donne que le lien : l'adresse vient de la personne.
    expect(array_keys(Arr::only($proprietes, ['token', 'adresse', 'compte', 'email', 'lienRefuse'])))->toBe(['token', 'adresse']);

    identifiantSaisiEnvoyer($lien, [
        ...$lienDeLaPage,
        'email' => $saisie,
        'password' => identifiantSaisiMotDePasse(),
        'password_confirmation' => identifiantSaisiMotDePasse(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    post(route('login'), ['email' => $saisie, 'password' => identifiantSaisiMotDePasse()]);

    $this->assertAuthenticatedAs($compte);
})->with('adresses saisies qui désignent le compte du lien');

/*
 * Chaque cas part d'un compte qui vient de demander un lien, et ouvre le vrai
 * lien : seule l'adresse saisie change. La référence est le jeton faux envoyé
 * avec la bonne adresse, depuis la même page.
 */
dataset('adresses saisies qui ne désignent pas le compte du lien', [
    'une adresse sans compte' => [static fn (): string => 'personne@example.org'],
    'l’adresse d’un autre compte' => [static fn (): string => User::factory()->create(['email' => 'voisin.saisie@example.org'])->email],
    'l’adresse du compte à une lettre près' => [static fn (): string => 'titulaire.saisi@example.org'],
    'l’adresse du compte sous un autre domaine' => [static fn (): string => 'titulaire.saisie@example.com'],
]);

it('refuse une adresse qui ne désigne pas le compte du lien exactement comme un jeton faux, et le jeton reste', function (Closure $adresseSaisie): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => identifiantSaisiAdresse()]);
    $jeton = Password::createToken($compte);
    $lien = identifiantSaisiLienPour($compte, $jeton);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, identifiantSaisiMotDePasse());
    $demande = DB::table('password_reset_tokens')->get()->all();

    $jetonFaux = identifiantSaisiCeQueLeClientLit($lien, [...$formulaire, 'token' => 'un-jeton-quelconque']);
    $refus = identifiantSaisiCeQueLeClientLit($lien, [...$formulaire, 'email' => $adresseSaisie()]);

    expect($jetonFaux['statut'])->toBe(302)
        ->and($jetonFaux['session']['errors'] ?? null)->toBe(['default' => ['token' => ['Ce jeton de réinitialisation du mot de passe n\'est pas valide.']]])
        ->and($refus)->toBe($jetonFaux)
        ->and(DB::table('password_reset_tokens')->get()->all())->toEqual($demande)
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue()
        ->and(Hash::check('password', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
})->with('adresses saisies qui ne désignent pas le compte du lien');

/*
 * Le courtier ne vérifie l'empreinte du jeton (bcrypt) qu'une fois le compte
 * trouvé : une adresse qui ne désigne pas le compte est refusée sans elle, un
 * jeton faux après elle. Les deux refus durent le plancher commun, sans
 * dormir pour de vrai (`DureeSimulee`).
 */
it('refuse dans le même temps, au plancher commun, une adresse qui ne désigne pas le compte et un jeton faux du même compte', function (): void {
    $duree = DureeSimulee::demarrer();
    $compte = User::factory()->create(['email' => identifiantSaisiAdresse()]);
    // Le jeton s'enregistre au coût de production, que la vérification d'un jeton faux paiera.
    config(['hashing.bcrypt.rounds' => identifiantSaisiCoutDeProduction()]);
    Hash::forgetDrivers();
    $jeton = Password::createToken($compte);
    $lien = identifiantSaisiLienPour($compte, $jeton);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, identifiantSaisiMotDePasse());

    $jetonFaux = $duree->mesurer(static fn (): TestResponse => identifiantSaisiEnvoyer($lien, [...$formulaire, 'token' => 'un-jeton-quelconque']));
    $jetonFaux['reponse']->assertRedirect($lien)->assertSessionHasErrors(['token' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);
    $mauvaiseAdresse = $duree->mesurer(static fn (): TestResponse => identifiantSaisiEnvoyer($lien, [...$formulaire, 'email' => 'personne@example.org']));
    $mauvaiseAdresse['reponse']->assertRedirect($lien)->assertSessionHasErrors(['token' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);

    expect($duree->plancher)->toBe(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES)
        ->and($jetonFaux['reel'])->toBeLessThan($duree->plancher)
        ->and($mauvaiseAdresse['reel'])->toBeLessThan($duree->plancher)
        ->and($jetonFaux['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($mauvaiseAdresse['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($jetonFaux['total'])->toBeLessThanOrEqual($duree->plancher + $jetonFaux['reel'] + 1_000)
        ->and($mauvaiseAdresse['total'])->toBeLessThanOrEqual($duree->plancher + $mauvaiseAdresse['reel'] + 1_000)
        ->and($jetonFaux['dormi'])->toBeGreaterThan(0.0)
        ->and($mauvaiseAdresse['dormi'])->toBeGreaterThan(0.0)
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue();
});

dataset('adresses saisies que la validation refuse', [
    'vide' => ['', 'Le champ adresse e-mail est obligatoire.'],
    'faite d’espaces' => ['   ', 'Le champ adresse e-mail est obligatoire.'],
    'absente' => [null, 'Le champ adresse e-mail est obligatoire.'],
    'mal formée' => ['titulaire.saisie', 'Le champ adresse e-mail doit être une adresse e-mail valide.'],
]);

it('demande l’adresse : vide, absente ou mal formée, l’envoi reçoit l’erreur de validation du champ, et le jeton reste', function (?string $saisie, string $message): void {
    $compte = User::factory()->create(['email' => identifiantSaisiAdresse()]);
    $jeton = Password::createToken($compte);
    $lien = identifiantSaisiLienPour($compte, $jeton);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, identifiantSaisiMotDePasse());

    /** @var array<string, mixed> $donnees */
    $donnees = $saisie === null ? Arr::except($formulaire, 'email') : [...$formulaire, 'email' => $saisie];

    identifiantSaisiEnvoyer($lien, $donnees)->assertRedirect($lien);

    expect(identifiantSaisiErreurs())->toBe(['email' => [$message]])
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue()
        ->and(Hash::check('password', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
})->with('adresses saisies que la validation refuse');

/*
 * L'adresse saisie désigne le compte comme la connexion le cherche : le
 * courtier ne trouve le compte du lien que s'il porte aussi l'adresse saisie,
 * selon la collation de `users.email` (`utf8mb4_unicode_ci`), après que
 * `TrimStrings` a ôté les espaces autour, comme pour la connexion. Ce test
 * essaie chaque variante des deux côtés, sur un compte neuf : la connexion avec
 * le mot de passe actuel, puis la réinitialisation, qui doivent dire la même
 * chose. La collation confond aussi une lettre accentuée avec la lettre nue.
 */
it('accepte à la réinitialisation exactement les adresses sous lesquelles la connexion trouve le compte', function (): void {
    $variantes = [
        'telle quelle' => static fn (string $adresse): string => $adresse,
        'en capitales' => strtoupper(...),
        'entre des espaces' => static fn (string $adresse): string => "  {$adresse}\t",
        'une lettre accentuée' => static fn (string $adresse): string => 'í'.substr($adresse, 1),
        'une lettre de moins' => static fn (string $adresse): string => substr($adresse, 1),
        'un autre domaine' => static fn (string $adresse): string => str_replace('.org', '.net', $adresse),
        'une espace avant l’arobase' => static fn (string $adresse): string => str_replace('@', ' @', $adresse),
    ];
    $verdicts = [];

    foreach ($variantes as $nom => $varier) {
        $compte = User::factory()->create(['email' => 'identifiant.'.count($verdicts).'@example.org']);
        $saisie = $varier($compte->email);
        $lien = identifiantSaisiLienPour($compte);
        Cache::flush();

        post(route('login'), ['email' => $saisie, 'password' => 'password']);
        $connexion = auth()->guard('web')->id() === $compte->id;
        auth()->guard('web')->logout();
        test()->flushSession();

        $reinitialisation = identifiantSaisiEnvoyer($lien, FormulaireDeReinitialisation::depuisLeLien($lien, identifiantSaisiMotDePasse(), $saisie))
            ->isRedirect(route('login'));

        $verdicts[$nom] = ['connexion' => $connexion, 'réinitialisation' => $reinitialisation];
    }

    expect($verdicts)->toBe([
        'telle quelle' => ['connexion' => true, 'réinitialisation' => true],
        'en capitales' => ['connexion' => true, 'réinitialisation' => true],
        'entre des espaces' => ['connexion' => true, 'réinitialisation' => true],
        'une lettre accentuée' => ['connexion' => true, 'réinitialisation' => true],
        'une lettre de moins' => ['connexion' => false, 'réinitialisation' => false],
        'un autre domaine' => ['connexion' => false, 'réinitialisation' => false],
        'une espace avant l’arobase' => ['connexion' => false, 'réinitialisation' => false],
    ]);
});
