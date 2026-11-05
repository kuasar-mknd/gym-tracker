<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\DureeSimulee;
use Tests\Support\FormulaireDeReinitialisation;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;

/*
 * Une adresse dont le domaine est internationalisé s'écrit sous deux formes
 * qui désignent la même boîte : Unicode (« jean@bücher.example.org ») et
 * ASCII (« jean@xn--bcher-kva.example.org ») (#2168). Le champ `type="email"`
 * de Chromium envoie l'ASCII ; un champ texte, un client hors navigateur ou un
 * navigateur qui ne convertirait pas, la saisie telle quelle. La recherche ne
 * connaissait que la forme reçue, selon la collation de `users.email`, qui ne
 * rapproche pas les deux formes : un compte enregistré en ASCII, la seule
 * forme que l'inscription crée depuis #2001, ne se connectait pas, ne
 * recevait pas le lien « mot de passe oublié » et ne se réinitialisait pas
 * sous la forme Unicode.
 *
 * La connexion, « mot de passe oublié » et la réinitialisation écrivent
 * désormais l'adresse saisie sous sa forme canonique (`AdresseCanonique`)
 * avant la recherche, que la base fait comme avant. La conversion ne lit pas
 * la base et vaut pour toute saisie : une adresse inconnue reçoit, sous
 * chaque forme, la réponse d'aujourd'hui, au même plancher. La clé du
 * limiteur de la connexion se calcule sur la même forme : les deux formes
 * d'un compte partagent leurs cinq essais, et la translittération ne fait
 * plus de « bücher » la clé de « bucher », un autre domaine.
 *
 * Chaque envoi part comme celui de la page, comme s'il était le premier de
 * l'appareil : session et limiteurs à neuf.
 */

/**
 * L'adresse du compte de chaque cas, enregistrée en ASCII comme l'inscription
 * l'enregistre depuis #2001.
 */
function deuxFormesAdresseDuCompte(): string
{
    return 'jean@xn--bcher-kva.example.org';
}

/**
 * Un envoi de formulaire comme la page le fait, depuis `$page`, comme s'il
 * était le premier de cet appareil : session et limiteurs à neuf.
 *
 * @param  array<string, string>  $donnees
 * @return TestResponse<Response>
 */
function deuxFormesEnvoyer(string $page, string $route, array $donnees): TestResponse
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from($page)
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route($route), $donnees);

    return $reponse;
}

/**
 * Un essai de connexion qui suit les précédents, sur la même session et le
 * même limiteur.
 *
 * @return TestResponse<Response>
 */
function deuxFormesSeConnecter(string $adresse, string $motDePasse): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from(route('login'))->post(route('login'), ['email' => $adresse, 'password' => $motDePasse]);

    return $reponse;
}

/**
 * Le formulaire de réinitialisation envoyé comme la page : en JSON, avec les
 * en-têtes d'une visite d'Inertia, depuis la page du lien
 * (`.ai/rules/tests.md`).
 *
 * @param  array<string, mixed>  $donnees
 * @return TestResponse<Response>
 */
function deuxFormesReinitialiser(string $lien, array $donnees): TestResponse
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
 * Tout ce que le client lit d'une réinitialisation, comme si elle était la
 * première de cet appareil (`CeQueLeClientLit`), sans la saisie que la
 * session garde pour la page suivante (`_old_input`) : elle recopie ce que
 * chaque cas envoie, qui diffère par construction, et Inertia ne la partage
 * pas.
 *
 * @param  array<string, mixed>  $donnees
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function deuxFormesCeQueLitLaReinitialisation(string $lien, array $donnees): array
{
    test()->flushSession();
    Cache::flush();

    $lu = CeQueLeClientLit::de(deuxFormesReinitialiser($lien, $donnees), $lien);
    unset($lu['session']['_old_input']);

    return $lu;
}

/**
 * Le jeton du lien « mot de passe oublié » parti au compte, ou null.
 */
function deuxFormesJetonRecuPar(User $compte): ?string
{
    $jeton = null;

    foreach (Notification::sent($compte, ResetPassword::class) as $avis) {
        assert($avis instanceof ResetPassword);
        $jeton = $avis->token;
    }

    return $jeton;
}

/**
 * Le message d'erreur que la session garde sous ce champ, ou null.
 */
function deuxFormesErreur(string $champ): ?string
{
    $erreurs = session('errors');

    return $erreurs instanceof ViewErrorBag ? $erreurs->getBag('default')->first($champ) : null;
}

/*
 * Chaque forme que le compte reçoit de la page : l'Unicode d'un champ texte,
 * en capitales et avec un « b » pleine chasse (Chromium envoie, de ces trois
 * saisies, la forme ASCII), et l'ASCII de Chromium, en capitales aussi.
 */
dataset('formes de l’adresse du compte', [
    'en Unicode' => ['jean@bücher.example.org'],
    'en Unicode et en capitales' => ['Jean@BÜCHER.Example.org'],
    'un « b » pleine chasse' => ["jean@\u{FF42}ücher.example.org"],
    'en ASCII' => ['jean@xn--bcher-kva.example.org'],
    'en ASCII et en capitales' => ['JEAN@XN--BCHER-KVA.EXAMPLE.ORG'],
]);

it('connecte un compte enregistré en ASCII sous chaque forme de son adresse', function (string $forme): void {
    $compte = User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);

    deuxFormesEnvoyer(route('login'), 'login', ['email' => $forme, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
})->with('formes de l’adresse du compte');

it('envoie le lien « mot de passe oublié » sous chaque forme de l’adresse, et le lien réinitialise sous la même forme', function (string $forme): void {
    Notification::fake();
    $compte = User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);

    deuxFormesEnvoyer(route('password.request'), 'password.email', ['email' => $forme])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentToTimes($compte, ResetPassword::class, 1);
    $jeton = deuxFormesJetonRecuPar($compte);
    assert($jeton !== null);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, $jeton);

    deuxFormesReinitialiser($lien, FormulaireDeReinitialisation::depuisLeLien($lien, 'Nouveau-mot-de-passe-2026!', $forme))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('Nouveau-mot-de-passe-2026!', (string) $compte->refresh()->password))->toBeTrue()
        ->and($compte->email)->toBe(deuxFormesAdresseDuCompte());
})->with('formes de l’adresse du compte');

/*
 * Une adresse inconnue, sous chaque forme, reçoit la réponse d'aujourd'hui :
 * à la connexion, le refus `auth.failed` qui garde la saisie telle quelle
 * pour la page suivante ; à « mot de passe oublié », `passwords.sent`. Et
 * cette réponse est entière celle que reçoit la même forme quand le compte
 * existe (mauvais mot de passe, lien parti) : la conversion ne dit rien d'un
 * compte.
 */
it('répond à une adresse inconnue, sous chaque forme, comme aujourd’hui et comme à un compte qui existe', function (string $forme): void {
    Notification::fake();
    $this->freezeSecond();

    $connexionInconnue = CeQueLeClientLit::de(deuxFormesEnvoyer(route('login'), 'login', ['email' => $forme, 'password' => 'pas-le-bon']), route('login'));
    $oubliInconnu = CeQueLeClientLit::de(deuxFormesEnvoyer(route('password.request'), 'password.email', ['email' => $forme]), route('password.request'));

    $compte = User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);

    $mauvaisMotDePasse = CeQueLeClientLit::de(deuxFormesEnvoyer(route('login'), 'login', ['email' => $forme, 'password' => 'pas-le-bon']), route('login'));
    $oubliConnu = CeQueLeClientLit::de(deuxFormesEnvoyer(route('password.request'), 'password.email', ['email' => $forme]), route('password.request'));

    expect($connexionInconnue['statut'])->toBe(302)
        ->and($connexionInconnue['session']['errors'] ?? null)->toBe(['default' => ['email' => [__('auth.failed')]]])
        ->and($connexionInconnue['session']['_old_input'] ?? null)->toBe(['email' => $forme])
        ->and($mauvaisMotDePasse)->toBe($connexionInconnue)
        ->and($oubliInconnu['statut'])->toBe(302)
        ->and($oubliInconnu['session']['status'] ?? null)->toBe(__('passwords.sent'))
        ->and($oubliInconnu['session'])->not->toHaveKey('errors')
        ->and($oubliConnu)->toBe($oubliInconnu);

    Notification::assertSentToTimes($compte, ResetPassword::class, 1);
    assertGuest();
})->with('formes de l’adresse du compte');

/*
 * À la réinitialisation, une adresse qui ne désigne pas le compte du lien,
 * sous chaque forme, reçoit le refus d'un jeton faux, réponse entière
 * comprise, et le jeton reste (`IdentifiantSaisiALaReinitialisationTest`).
 */
it('refuse à la réinitialisation, sous chaque forme, une adresse qui ne désigne pas le compte du lien, comme un jeton faux', function (string $forme): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);
    $jeton = Password::createToken($compte);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, $jeton);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, 'Nouveau-mot-de-passe-2026!');

    $jetonFaux = deuxFormesCeQueLitLaReinitialisation($lien, [...$formulaire, 'token' => 'un-jeton-quelconque']);
    $refus = deuxFormesCeQueLitLaReinitialisation($lien, [...$formulaire, 'email' => str_replace('jean@', 'personne@', $forme)]);

    expect($jetonFaux['session']['errors'] ?? null)->toBe(['default' => ['token' => [__('passwords.token')]]])
        ->and($refus)->toBe($jetonFaux)
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue()
        ->and(Hash::check('password', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
})->with([
    'en Unicode' => ['jean@bücher.example.org'],
    'un « b » pleine chasse' => ["jean@\u{FF42}ücher.example.org"],
    'en ASCII' => ['jean@xn--bcher-kva.example.org'],
]);

/*
 * La conversion ne lit pas la base : le refus d'une adresse inconnue saisie
 * en Unicode dure le plancher commun des réponses d'authentification, comme
 * celui de toute adresse (`DureeDesReponsesDAuthentificationTest`), sans
 * dormir pour de vrai (`DureeSimulee`).
 */
it('fait durer le plancher commun au refus d’une adresse inconnue saisie en Unicode', function (Closure $preparer): void {
    Notification::fake();
    $duree = DureeSimulee::demarrer();
    /** @var Closure(): mixed $requete */
    $requete = $preparer();
    Cache::flush();

    $mesure = $duree->mesurer($requete);

    expect($duree->plancher)->toBe(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES)
        ->and($mesure['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($mesure['total'])->toBeLessThanOrEqual($duree->plancher + $mesure['reel'] + 1_000)
        ->and($mesure['dormi'])->toBeGreaterThan(0.0)
        ->and($mesure['reel'])->toBeLessThan($duree->plancher);
})->with([
    'connexion' => [static fn (): Closure => static fn (): mixed => test()->post(route('login'), ['email' => 'personne@bücher.example.org', 'password' => 'password'])
        ->assertSessionHasErrors('email')],
    'mot de passe oublié' => [static fn (): Closure => static fn (): mixed => test()->post(route('password.email'), ['email' => 'personne@bücher.example.org'])
        ->assertSessionHasNoErrors()],
    'réinitialisation' => [static function (): Closure {
        $compte = User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);
        $formulaire = FormulaireDeReinitialisation::pour($compte, 'Nouveau-mot-de-passe-2026!');
        $formulaire['email'] = 'personne@bücher.example.org';

        return static fn (): mixed => test()->post(route('password.store'), $formulaire)->assertSessionHasErrors('token');
    }],
]);

/*
 * Les cinq essais de la connexion se comptent sous la forme canonique de
 * l'adresse (`LoginRequest::throttleKey()`) : sur la saisie brute, chaque
 * forme d'un compte aurait eu les siens, et la translittération faisait de
 * « bücher » la clé de « bucher ». Au sixième essai, le bon mot de passe
 * reçoit le refus du limiteur, quelle que soit la forme.
 */
it('compte sous une seule clé les essais de la connexion, quelle que soit la forme de l’adresse', function (string $formeDesEssais, string $formeSuivante): void {
    User::factory()->create(['email' => deuxFormesAdresseDuCompte()]);
    Cache::flush();

    foreach (range(1, 5) as $_essai) {
        deuxFormesSeConnecter($formeDesEssais, 'pas-le-bon')->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    deuxFormesSeConnecter($formeSuivante, 'password')->assertSessionHasErrors('email');

    expect(deuxFormesErreur('email'))->toStartWith('Trop d’essais. Réessaie dans ');
    assertGuest();
})->with([
    'Unicode, puis ASCII' => ['jean@bücher.example.org', 'jean@xn--bcher-kva.example.org'],
    'ASCII, puis Unicode' => ['jean@xn--bcher-kva.example.org', 'jean@bücher.example.org'],
    'pleine chasse, puis capitales' => ["jean@\u{FF42}ücher.example.org", 'JEAN@BÜCHER.EXAMPLE.ORG'],
]);

it('ne compte pas les essais d’un domaine internationalisé sous la clé d’un domaine voisin', function (): void {
    $voisin = User::factory()->create(['email' => 'jean@bucher.example.org']);
    Cache::flush();

    foreach (range(1, 5) as $_essai) {
        deuxFormesSeConnecter('jean@bücher.example.org', 'pas-le-bon')->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    deuxFormesSeConnecter('jean@bucher.example.org', 'password')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($voisin);
});

/*
 * Un compte enregistré en Unicode que la migration de #2168 laisse, parce
 * que sa forme ASCII est portée par un autre compte, ne s'ouvre plus par
 * mot de passe ni ne reçoit le lien : chaque forme saisie trouve le compte
 * ASCII, et le lien part à son adresse, comme aujourd'hui depuis Chromium.
 * Rien n'est fusionné ni supprimé ; le propriétaire décide de chaque doublon.
 */
it('ne trouve sous aucune forme le compte resté en Unicode d’un doublon, et trouve le compte ASCII', function (string $forme): void {
    Notification::fake();
    $compteAscii = User::factory()->create(['email' => deuxFormesAdresseDuCompte(), 'password' => 'Mot-de-passe-du-compte-ascii-1!']);
    $compteUnicode = User::factory()->create(['email' => 'jean@bücher.example.org', 'password' => 'Mot-de-passe-du-compte-unicode-1!']);

    deuxFormesEnvoyer(route('login'), 'login', ['email' => $forme, 'password' => 'Mot-de-passe-du-compte-unicode-1!'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    assertGuest();

    deuxFormesEnvoyer(route('login'), 'login', ['email' => $forme, 'password' => 'Mot-de-passe-du-compte-ascii-1!'])
        ->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($compteAscii);
    auth()->guard('web')->logout();

    deuxFormesEnvoyer(route('password.request'), 'password.email', ['email' => $forme])->assertSessionHasNoErrors();

    Notification::assertSentToTimes($compteAscii, ResetPassword::class, 1);
    Notification::assertNotSentTo($compteUnicode, ResetPassword::class);
    expect(User::query()->count())->toBe(2)
        ->and($compteUnicode->refresh()->email)->toBe('jean@bücher.example.org');
})->with([
    'en Unicode' => ['jean@bücher.example.org'],
    'en ASCII' => ['jean@xn--bcher-kva.example.org'],
]);
