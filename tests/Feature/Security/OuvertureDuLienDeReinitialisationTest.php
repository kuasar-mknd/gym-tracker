<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use App\Support\LiensDesCourriels;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\DureeSimulee;
use Tests\Support\FormulaireDeReinitialisation;

use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * La page qu'ouvre le lien du courriel « mot de passe oublié » vérifie le lien
 * avant d'ouvrir le formulaire, et ne donne jamais l'adresse du compte.
 *
 * Depuis #2058, le lien ne porte l'adresse du compte que chiffrée, et la page
 * ne la montrait plus : un gestionnaire de mots de passe rangeait alors le
 * nouveau mot de passe sans identifiant. #2070 l'a d'abord montrée en lecture
 * seule pour un lien qui vaut, ce qui donnait l'identifiant de la connexion à
 * quiconque tenait le lien. Le propriétaire a choisi l'autre voie
 * (2026-10-16) : la page d'un lien qui vaut n'envoie l'adresse sous aucune
 * forme, et la personne, ou son gestionnaire, la saisit dans un champ vide
 * (`autocomplete="username"`), que le serveur compare à l'adresse du lien
 * (`IdentifiantSaisiALaReinitialisationTest`). La page sort comme une page de
 * compte, historique chiffré et document en `no-store`, pour le lien qu'elle
 * porte (jeton et valeur chiffrée).
 *
 * Tout autre lien reçoit la même page, sans formulaire, après le même plancher
 * de durée (`DureeDesReponsesDAuthentification`) : ni la page ni sa durée ne
 * disent, à qui tient un vieux lien, si le compte existe encore ou s'il a
 * demandé un lien depuis. La vérification ne consomme ni ne prolonge le jeton
 * du titulaire. Le côté client (champ vide et modifiable,
 * `autocomplete="username"`, envoyé avec le formulaire ; historique chiffré
 * par le vrai client d'Inertia) est tenu par tests/js/pages/authPages.test.js
 * et tests/js/pages/reinitialisationDansLHistorique.test.js.
 */

/**
 * L'adresse du compte de chaque cas. Sa partie locale ne se trouve nulle part
 * ailleurs : la lire dans un texte, quelle qu'en soit la casse, c'est y lire
 * l'adresse.
 */
function ouvertureDuLienAdresse(): string
{
    return 'titulaire.ouverture@example.org';
}

/**
 * Ce texte laisse-t-il lire l'adresse du compte ?
 */
function ouvertureDuLienLaisseLireLAdresse(string $texte): bool
{
    return str_contains(strtolower($texte), 'titulaire.ouverture');
}

/**
 * Le lien du courriel pour ce compte, tel que l'application le bâtit sur
 * cette origine, avec un jeton neuf du courtier ou celui donné.
 */
function ouvertureDuLienPour(User $compte, ?string $jeton = null, string $origine = 'https://gym.example.org'): string
{
    config(['app.url' => $origine]);

    return LiensDesCourriels::lienDeReinitialisation($compte, $jeton ?? Password::createToken($compte));
}

/**
 * Ce que la page de réinitialisation reçoit en propre, hors des props que
 * toutes les pages partagent.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array<string, mixed>
 */
function ouvertureDuLienPropsDeLaPage(TestResponse $reponse): array
{
    /** @var array<string, mixed> $proprietes */
    $proprietes = $reponse->inertiaProps();
    /** @var array<string, mixed> $propres */
    $propres = Arr::only($proprietes, ['token', 'adresse', 'compte', 'lienRefuse', 'email']);

    return $propres;
}

/**
 * La page Inertia d'un chargement complet, lue dans son `data-page`.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array<string, mixed>
 */
function ouvertureDuLienPageInertia(TestResponse $reponse): array
{
    /** @var array<string, mixed> $page */
    $page = $reponse->inertiaPage();

    return $page;
}

/**
 * Tout ce que le client lit de l'ouverture de ce lien par une visite
 * d'Inertia, comme si elle était la première de cet appareil
 * (`CeQueLeClientLit`), le lien lui-même ôté : il diffère d'un cas à l'autre
 * par construction (jeton du chemin, valeur chiffrée), et la page le recopie
 * dans son adresse (`url`). Une visite plutôt qu'un chargement complet : le
 * document complet porte aussi la table des routes de Ziggy, écrite en entier
 * à sa première page du processus puis complétée aux suivantes, si bien que
 * deux documents ne se comparent pas d'un test à l'autre.
 *
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function ouvertureDuLienCeQueLeClientLit(string $lien): array
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ])->get($lien);
    $adresseDeLaPage = $reponse->json('url');
    assert(is_string($adresseDeLaPage));

    $lu = CeQueLeClientLit::de($reponse, route('password.request'));
    $lu['corps'] = str_replace(trim((string) json_encode($adresseDeLaPage), '"'), '…', (string) $lu['corps']);
    unset($lu['session']['_previous']);

    return $lu;
}

/**
 * La valeur chiffrée d'un lien, un caractère changé en son milieu.
 */
function ouvertureDuLienAlterer(string $valeur): string
{
    $milieu = intdiv(strlen($valeur), 2);

    return substr_replace($valeur, $valeur[$milieu] === 'A' ? 'B' : 'A', $milieu, 1);
}

/**
 * Le même lien, la valeur chiffrée remplacée (null : retirée).
 *
 * @param  array<string, mixed>  $parametres
 */
function ouvertureDuLienAvec(string $lien, array $parametres): string
{
    parse_str((string) parse_url($lien, PHP_URL_QUERY), $requete);

    return Str::before($lien, '?').'?'.http_build_query(array_filter([...$requete, ...$parametres], static fn (mixed $valeur): bool => $valeur !== null));
}

it('ouvre, pour un lien qui vaut, le formulaire sans l’adresse du compte, et lui rend la valeur chiffrée telle que le lien la porte', function (): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $lien = ouvertureDuLienPour($compte);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, 'Un-nouveau-mot-de-passe-2026!');

    $page = get($lien)->assertOk();

    expect(ouvertureDuLienPageInertia($page)['component'] ?? null)->toBe('Auth/ResetPassword')
        ->and(ouvertureDuLienPropsDeLaPage($page))->toBe([
            'token' => $formulaire['token'],
            'adresse' => $formulaire['adresse'],
        ])
        ->and(ouvertureDuLienLaisseLireLAdresse((string) $page->getContent()))->toBeFalse();
});

it('ouvre le formulaire d’un compte en attente sans son adresse, même quand le compte la porte dans une autre casse', function (): void {
    $compte = User::factory()->enAttente()->create(['email' => ouvertureDuLienAdresse()]);
    $lien = ouvertureDuLienPour($compte);
    $compte->forceFill(['email' => 'Titulaire.Ouverture@example.org'])->save();

    $page = get($lien)->assertOk();

    expect(array_keys(ouvertureDuLienPropsDeLaPage($page)))->toBe(['token', 'adresse'])
        ->and(ouvertureDuLienLaisseLireLAdresse((string) $page->getContent()))->toBeFalse();
});

/*
 * Ce que la page ne donne plus (`.ai/rules/controllers.md`). Un lien qui vaut
 * montrait l'adresse du compte, en lecture seule : partie de la seule URL,
 * sans rien savoir du compte, une personne lisait l'identifiant de la
 * connexion, changeait le mot de passe et se connectait. La page d'un lien
 * qui vaut ne porte plus l'adresse sous aucune forme : ni dans ses props, ni
 * dans le document complet (la page initiale du `data-page` comprise), ni
 * dans la réponse d'une visite d'Inertia, ni dans les en-têtes, quelle que
 * soit la façon dont le navigateur sait chiffrer l'historique. La personne la
 * saisit (`IdentifiantSaisiALaReinitialisationTest`).
 */
it('ne donne l’adresse du compte nulle part à qui ouvre un lien qui vaut : ni props, ni document complet, ni visite d’Inertia, ni en-têtes', function (string $origine): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $lien = ouvertureDuLienPour($compte, origine: $origine);

    $document = get($lien)->assertOk();
    /** @var TestResponse<Response> $visite */
    $visite = test()->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
    ])->get($lien)->assertOk();
    $traces = [
        'page du document' => (string) json_encode(ouvertureDuLienPageInertia($document)),
        'document complet' => (string) $document->getContent(),
        'en-têtes du document' => (string) $document->headers,
        'visite d’Inertia' => (string) $visite->getContent(),
        'en-têtes de la visite' => (string) $visite->headers,
        'session' => serialize(session()->all()),
    ];

    /** @var array<string, mixed> $proprietesDeLaVisite */
    $proprietesDeLaVisite = $visite->json('props');

    expect(array_keys(ouvertureDuLienPropsDeLaPage($document)))->toBe(['token', 'adresse'])
        ->and(array_keys(Arr::only($proprietesDeLaVisite, ['token', 'adresse', 'compte', 'lienRefuse', 'email'])))->toBe(['token', 'adresse'])
        ->and(array_keys(array_filter($traces, ouvertureDuLienLaisseLireLAdresse(...))))->toBe([]);
})->with([
    'en HTTPS, derrière le proxy inverse' => ['https://gym.example.org'],
    'sur localhost' => ['http://localhost'],
    'en http hors de la boucle locale' => ['http://laravel.test'],
]);

/*
 * Inertia range chaque page dans l'historique du navigateur, que le bouton
 * Retour relit sans requête, et le cache HTTP peut resservir un document
 * gardé : la page d'un lien qui vaut, qui porte le lien lui-même (jeton et
 * valeur chiffrée), sort comme une page de compte
 * (`HandleInertiaRequests::traiterCommeUnePageDeCompte()`).
 */
it('chiffre l’historique de la page d’un lien qui vaut, et la tient hors du cache', function (string $origine): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);

    $page = get(ouvertureDuLienPour($compte, origine: $origine))->assertOk();

    expect(ouvertureDuLienPageInertia($page)['encryptHistory'] ?? false)->toBeTrue()
        ->and(array_keys(ouvertureDuLienPropsDeLaPage($page)))->toBe(['token', 'adresse'])
        ->and($page->headers->get('Cache-Control'))->toBe('no-store, private');
})->with([
    'en HTTPS, derrière le proxy inverse' => ['https://gym.example.org'],
    'sur localhost' => ['http://localhost'],
]);

it('ne chiffre pas l’historique là où le navigateur ne saurait pas, et garde le formulaire, sans l’adresse', function (): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $lien = ouvertureDuLienPour($compte, origine: 'http://laravel.test');

    $page = get($lien)->assertOk();
    $proprietes = ouvertureDuLienPropsDeLaPage($page);

    expect(ouvertureDuLienPageInertia($page))->not->toHaveKey('encryptHistory')
        ->and(array_keys($proprietes))->toBe(['token', 'adresse'])
        ->and($proprietes['token'])->toBeString()
        ->and($proprietes['adresse'])->toBeString()
        ->and(ouvertureDuLienLaisseLireLAdresse((string) $page->getContent()))->toBeFalse()
        ->and($page->headers->get('Cache-Control'))->toBe('no-store, private');

    post(route('password.store'), FormulaireDeReinitialisation::depuisLeLien($lien, 'Un-nouveau-mot-de-passe-2026!'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'));
});

/*
 * Chaque cas part d'un compte qui vient de demander un lien : seul le lien
 * ouvert change. La référence est le lien d'une adresse qui n'a pas de compte.
 */
dataset('liens de réinitialisation qui ne valent pas', [
    'un jeton faux' => [static fn (string $lien, string $jeton): string => str_replace($jeton, 'un-jeton-quelconque', $lien)],
    'un jeton périmé' => [static function (string $lien): string {
        test()->travel(61)->minutes();

        return $lien;
    }],
    'un jeton déjà servi' => [static function (string $lien): string {
        post(route('password.store'), FormulaireDeReinitialisation::depuisLeLien($lien, 'Un-nouveau-mot-de-passe-2026!'))
            ->assertRedirect(route('login'));

        return $lien;
    }],
    'une valeur chiffrée altérée' => [static function (string $lien): string {
        parse_str((string) parse_url($lien, PHP_URL_QUERY), $requete);
        assert(is_string($requete['adresse'] ?? null));

        return ouvertureDuLienAvec($lien, ['adresse' => ouvertureDuLienAlterer($requete['adresse'])]);
    }],
    'une valeur chiffrée sous une autre clef' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, [
        'adresse' => new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc')->encryptString(ouvertureDuLienAdresse()),
    ])],
    'l’adresse en clair à la place de la valeur chiffrée' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, ['adresse' => ouvertureDuLienAdresse()])],
    'un lien d’avant le chiffrement (?email=)' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, ['adresse' => null, 'email' => ouvertureDuLienAdresse()])],
    'aucune valeur chiffrée' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, ['adresse' => null])],
    'une valeur chiffrée vide' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, ['adresse' => ''])],
    'une valeur chiffrée qui n’est pas une chaîne' => [static fn (string $lien): string => ouvertureDuLienAvec($lien, ['adresse' => ['une', 'liste']])],
    'le lien d’un compte supprimé depuis' => [static function (string $lien): string {
        User::query()->where('email', ouvertureDuLienAdresse())->sole()->delete();

        return $lien;
    }],
]);

it('refuse, dès l’ouverture et à l’identique, tout lien qui ne vaut pas : ni adresse, ni formulaire', function (Closure $alterer): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $jeton = Password::createToken($compte);
    $lienDuCourriel = ouvertureDuLienPour($compte, $jeton);
    /** @var string $lien */
    $lien = $alterer($lienDuCourriel, $jeton);

    $sansCompte = ouvertureDuLienCeQueLeClientLit(ouvertureDuLienAvec(
        str_replace($jeton, 'un-jeton-quelconque', $lienDuCourriel),
        ['adresse' => Crypt::encryptString('personne@example.org')],
    ));
    $refus = ouvertureDuLienCeQueLeClientLit($lien);
    $page = get($lien)->assertOk();
    $sansLeLien = str_replace(trim((string) json_encode(ouvertureDuLienPageInertia($page)['url'] ?? ''), '"'), '…', (string) $page->getContent());

    expect($sansCompte['statut'])->toBe(200)
        ->and(ouvertureDuLienPropsDeLaPage($page))->toBe(['lienRefuse' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.'])
        ->and(ouvertureDuLienPageInertia($page))->not->toHaveKey('encryptHistory')
        ->and(ouvertureDuLienLaisseLireLAdresse($sansLeLien))->toBeFalse()
        ->and($refus)->toBe($sansCompte);
})->with('liens de réinitialisation qui ne valent pas');

/*
 * Le courtier ne vérifie l'empreinte du jeton (bcrypt) que pour un compte qui
 * a une demande en cours : au coût de production, l'ouverture d'un vieux lien
 * durait plus longtemps quand son compte venait de demander un lien. Le
 * plancher commun couvre tout refus ; un lien qui vaut rend la main sans
 * l'attendre.
 */
function ouvertureDuLienCoutDeProduction(): int
{
    if (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', File::get(base_path('.env.example')), $cout) !== 1) {
        throw new RuntimeException('.env.example ne donne plus BCRYPT_ROUNDS.');
    }

    return (int) $cout[1];
}

it('ouvre dans le même temps un lien sans compte et le jeton faux d’un compte qui vient de demander un lien', function (): void {
    $duree = DureeSimulee::demarrer();
    $lien = route('password.reset', [
        'token' => 'un-jeton-quelconque',
        'adresse' => Crypt::encryptString(ouvertureDuLienAdresse()),
    ]);
    $ouvrir = static fn (): TestResponse => get($lien)->assertOk();

    $sansCompte = $duree->mesurer($ouvrir);

    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    config(['hashing.bcrypt.rounds' => ouvertureDuLienCoutDeProduction()]);
    Hash::forgetDrivers();
    Password::createToken($compte);

    $jetonFaux = $duree->mesurer($ouvrir);

    // Le travail du refus le plus long, hors du plancher : la vérification de l'empreinte.
    $debut = microtime(true);
    expect(Password::tokenExists($compte, 'un-jeton-quelconque'))->toBeFalse();
    $travail = (microtime(true) - $debut) * 1_000_000;

    expect($duree->plancher)->toBe(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES)
        ->and($travail)->toBeLessThan($duree->plancher)
        ->and(ouvertureDuLienPropsDeLaPage($sansCompte['reponse']))->toHaveKey('lienRefuse')
        ->and(ouvertureDuLienPropsDeLaPage($jetonFaux['reponse']))->toHaveKey('lienRefuse')
        ->and($sansCompte['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($jetonFaux['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($sansCompte['dormi'])->toBeGreaterThan(0.0)
        ->and($jetonFaux['dormi'])->toBeGreaterThan(0.0);
});

it('ouvre un lien qui vaut sans attendre le plancher des refus', function (): void {
    $duree = DureeSimulee::demarrer();
    $lien = ouvertureDuLienPour(User::factory()->create(['email' => ouvertureDuLienAdresse()]));

    $ouverture = $duree->mesurer(static fn (): TestResponse => get($lien)->assertOk());

    expect(array_keys(ouvertureDuLienPropsDeLaPage($ouverture['reponse'])))->toBe(['token', 'adresse'])
        ->and($ouverture['dormi'])->toBe(0.0)
        ->and($ouverture['total'])->toBeLessThan($duree->plancher);
});

it('ne consomme ni ne prolonge le jeton du titulaire, que le lien ouvert vaille ou non', function (): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $jeton = Password::createToken($compte);
    $lien = ouvertureDuLienPour($compte, $jeton);
    $demande = DB::table('password_reset_tokens')->get()->all();

    $this->travel(10)->minutes();
    Cache::flush();
    get($lien)->assertOk();
    get(str_replace($jeton, 'un-jeton-quelconque', $lien))->assertOk();
    get($lien)->assertOk();

    expect(DB::table('password_reset_tokens')->get()->all())->toEqual($demande)
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue();

    // Il expire toujours une heure après la demande, pas après l'ouverture.
    $this->travel(51)->minutes();
    Cache::flush();

    expect(ouvertureDuLienPropsDeLaPage(get($lien)->assertOk()))->toHaveKey('lienRefuse');
});

it('n’écrit l’adresse ni dans la session, ni dans l’adresse gardée pour après la connexion, ni dans le journal', function (): void {
    $fichier = sys_get_temp_dir().'/ouverture-du-lien-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.ouverture_du_lien', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'ouverture_du_lien');

    try {
        $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
        $jeton = Password::createToken($compte);
        $lien = ouvertureDuLienPour($compte, $jeton);

        $ouverture = get($lien)->assertOk();
        $traces = ['session après un lien qui vaut' => serialize(session()->all())];
        get(str_replace($jeton, 'un-jeton-quelconque', $lien))->assertOk();
        $traces['session après un lien refusé'] = serialize(session()->all());
        $traces['journal'] = is_file($fichier) ? (string) file_get_contents($fichier) : '';

        // Le formulaire ouvert dit que le lien vaut : l'adresse a été déchiffrée et le compte trouvé.
        expect(array_keys(ouvertureDuLienPropsDeLaPage($ouverture)))->toBe(['token', 'adresse'])
            ->and(array_keys(array_filter($traces, ouvertureDuLienLaisseLireLAdresse(...))))->toBe([])
            ->and(session('url.intended'))->toBeNull();
    } finally {
        if (is_file($fichier)) {
            unlink($fichier);
        }
    }
});

/*
 * Une panne pendant la vérification, l'adresse déjà déchiffrée et le compte
 * trouvé : ni le journal, ni l'exception que le panneau garde, ni la réponse
 * ne la reçoivent. La pile est réglée comme dans l'image, d'après
 * `docker/php-prod.ini`, et sans `APP_DEBUG`, comme en production
 * (`LienDeReinitialisationSansAdresseEnClairTest` le fait de même pour
 * l'envoi du formulaire, et y dit pourquoi les colonnes `query` et `markdown`
 * n'en sont pas).
 */
it('n’écrit l’adresse ni dans le journal ni dans l’exception d’une ouverture qui échoue', function (): void {
    Config::set('app.debug', false);
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;
    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');
    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');

    $fichier = sys_get_temp_dir().'/ouverture-du-lien-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.ouverture_du_lien', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'ouverture_du_lien');

    try {
        $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
        $lien = ouvertureDuLienPour($compte);
        DB::listen(static function (QueryExecuted $requete): void {
            if (str_contains($requete->sql, 'password_reset_tokens')) {
                throw new RuntimeException('Panne simulée pendant l’ouverture du lien.');
            }
        });

        $reponse = get($lien)->assertInternalServerError();

        $exception = ExceptionEnregistree::query()->sole();
        $journal = is_file($fichier) ? (string) file_get_contents($fichier) : '';
        $traces = ['réponse' => (string) $reponse->getContent(), 'journal' => $journal];

        foreach (Arr::except($exception->getAttributes(), ['query', 'markdown']) as $colonne => $valeur) {
            $traces[$colonne] = is_scalar($valeur) ? (string) $valeur : (string) json_encode($valeur);
        }

        expect($exception->message)->toBe('Panne simulée pendant l’ouverture du lien.')
            ->and($journal)->toContain('Panne simulée pendant l’ouverture du lien.')
            ->and(array_keys(array_filter($traces, ouvertureDuLienLaisseLireLAdresse(...))))->toBe([]);
    } finally {
        ini_restore('zend.exception_ignore_args');

        if (is_file($fichier)) {
            unlink($fichier);
        }
    }
});

/*
 * Chaque refus de l'ouverture retient un processus de l'application le temps
 * du plancher, et chaque ouverture d'un compte qui a une demande en cours
 * vérifie l'empreinte du jeton (bcrypt) : l'ouverture a donc un limiteur. Pas
 * celui des autres routes d'authentification : un envoi du formulaire refusé
 * par la validation (confirmation différente, mot de passe trop court ou
 * compromis) revient sur la page du lien, que la redirection rouvre, si bien
 * que chaque essai y comptait deux fois, et que le troisième refus d'affilée
 * recevait un 429 au lieu de son message. Le sien, à part, en laisse douze par
 * minute à une adresse IP : une ouverture par envoi, que le limiteur commun
 * laisse passer six fois, et autant pour le lien lui-même.
 */
it('laisse à l’envoi du formulaire ses six essais par minute : chaque refus revient sur la page du lien, avec son message', function (): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $lien = ouvertureDuLienPour($compte);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, 'Un-nouveau-mot-de-passe-2026!');
    $refus = [
        'une confirmation différente' => [...$formulaire, 'password_confirmation' => 'Une-autre-confirmation-2026!'],
        'un mot de passe trop court' => [...$formulaire, 'password' => 'court', 'password_confirmation' => 'court'],
    ];

    get($lien)->assertOk();

    foreach (range(1, 6) as $essai) {
        $cas = array_keys($refus)[$essai % 2];
        $envoi = from($lien)->post(route('password.store'), $refus[$cas])->assertRedirect($lien);
        $page = get((string) $envoi->headers->get('Location'))->assertOk();
        /** @var array<string, mixed> $proprietes */
        $proprietes = $page->inertiaProps();
        /** @var array<string, mixed> $erreurs */
        $erreurs = $proprietes['errors'] ?? [];

        expect(array_keys($erreurs))->toBe(['password'], "Essai {$essai}, {$cas}")
            ->and(ouvertureDuLienPropsDeLaPage($page)['token'] ?? null)->toBe($formulaire['token']);
    }

    // Le septième envoi de la minute bute, comme avant, sur le limiteur commun.
    post(route('password.store'), $formulaire)->assertTooManyRequests();
    expect(Password::tokenExists($compte, $formulaire['token']))->toBeTrue();
});

it('borne les ouvertures du lien par un limiteur à part, sans rien prendre à celui des autres routes d’authentification', function (): void {
    $compte = User::factory()->create(['email' => ouvertureDuLienAdresse()]);
    $jeton = Password::createToken($compte);
    $lien = ouvertureDuLienPour($compte, $jeton);
    $refuse = str_replace($jeton, 'un-jeton-quelconque', $lien);

    foreach (range(1, 12) as $_ouverture) {
        get($refuse)->assertOk();
    }

    get($refuse)->assertTooManyRequests();
    get($lien)->assertTooManyRequests();

    foreach (range(1, 6) as $_demande) {
        post(route('password.email'), ['email' => ouvertureDuLienAdresse()])->assertRedirect();
    }

    post(route('password.email'), ['email' => ouvertureDuLienAdresse()])->assertTooManyRequests();
});
