<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FormulaireDeReinitialisation;

use function Pest\Laravel\get;

/*
 * L'adresse qu'une personne tape pour se connecter ou pour réinitialiser son
 * mot de passe ne s'écrit en clair dans aucune trace d'une exception levée
 * pendant l'envoi, comme le mot de passe (#PR) : ni dans le journal, ni dans
 * ce que le gestionnaire d'exceptions rapporte, ni dans l'exception que le
 * panneau garde trente jours (`ExceptionEnregistree`). Le paquet de cette
 * dernière recopie le corps entier de la requête, où l'adresse restait en
 * clair, que la personne ait un compte ou non, et même quand elle avait tapé
 * son mot de passe dans le champ de l'adresse.
 *
 * La panne est celle qu'une requête rencontre le plus : la base ne répond
 * plus quand l'application cherche le compte. L'adresse tapée ne désigne
 * aucun compte, et aucune requête SQL ne la lit avant la panne : avec
 * `APP_DEBUG`, celui de la CI, Laravel garde les requêtes réussies, valeurs
 * comprises, depuis le démarrage, préparation du test comprise
 * (`.ai/rules/tests.md`), et l'exception les recopie (`query`, `markdown`) ;
 * la production, sans `APP_DEBUG`, ne les remplit pas. Une copie de l'adresse
 * tapée ne peut donc venir que de la requête, et le test la cherche dans
 * chaque colonne. La pile est réglée comme dans l'image, d'après
 * `docker/php-prod.ini` (`LesErreursSqlNeRecopientPasLesValeursTest`).
 *
 * La saisie que la session garde après un refus de validation
 * (`_old_input`) n'est pas une trace d'exception : elle reste, et
 * `OuvertureDuCompteTest` comme `InscriptionMemeReponseTest` la lisent.
 */

beforeEach(function (): void {
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;

    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');

    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

/**
 * L'adresse que la personne tape, avec des capitales : une copie se
 * reconnaît à sa partie locale, quelle que soit sa casse
 * (`identifiantsHorsDesExceptionsLaissentLire()`).
 */
function identifiantsHorsDesExceptionsAdresseTapee(): string
{
    return 'Titulaire.Des-Traces@Example.org';
}

/**
 * Ce texte laisse-t-il lire l'adresse tapée, en clair ou encodée dans une
 * URL, sous n'importe quelle casse ?
 */
function identifiantsHorsDesExceptionsLaissentLire(string $texte): bool
{
    return str_contains(strtolower($texte), 'titulaire.des-traces');
}

/**
 * La base ne répond plus dès que l'application lit `$table` : la requête
 * n'est pas exécutée, et la panne remonte telle quelle.
 */
function identifiantsHorsDesExceptionsCouperLaTable(string $table): void
{
    DB::beforeExecuting(static function (string $requete) use ($table): void {
        if (str_contains($requete, "`{$table}`")) {
            throw new RuntimeException('La base ne répond plus.');
        }
    });
}

/**
 * Le journal écrit dans un fichier à part, que `$test` reçoit, effacé
 * ensuite ; et ce que le gestionnaire d'exceptions rapporte pendant `$test`,
 * message et pile de chaque exception, en texte.
 *
 * @param  Closure(): TestResponse<Response>  $test
 * @return array{reponse: TestResponse<Response>, journal: string, rapport: list<string>}
 */
function identifiantsHorsDesExceptionsTracesDe(Closure $test): array
{
    $fichier = sys_get_temp_dir().'/identifiants-hors-des-exceptions-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.identifiants_hors_des_exceptions', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'identifiants_hors_des_exceptions');

    $rapport = [];
    $gestionnaire = app(ExceptionHandler::class);
    assert($gestionnaire instanceof Handler);
    $gestionnaire->reportable(static function (Throwable $exception) use (&$rapport): void {
        $rapport[] = $exception->getMessage()."\n".$exception->getTraceAsString()."\n".json_encode($exception->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    try {
        $reponse = $test();
        $journal = is_file($fichier) ? (string) file_get_contents($fichier) : '';
    } finally {
        if (is_file($fichier)) {
            unlink($fichier);
        }
    }

    return ['reponse' => $reponse, 'journal' => $journal, 'rapport' => $rapport];
}

/**
 * Chaque colonne de l'exception enregistrée, en texte, telle que la base la
 * garde.
 *
 * @return array<string, string>
 */
function identifiantsHorsDesExceptionsColonnes(ExceptionEnregistree $exception): array
{
    return array_map(
        static fn (mixed $valeur): string => is_scalar($valeur) ? (string) $valeur : (string) json_encode($valeur),
        $exception->getAttributes(),
    );
}

/*
 * Les deux formes que prend un envoi : en JSON, comme la page (Inertia
 * sérialise le formulaire, avec ses en-têtes), et encodé comme un formulaire
 * HTML. La requête validée partage son corps JSON avec celle de
 * l'application (`.ai/rules/tests.md`) : le corps enregistré se lit sous les
 * deux.
 */
dataset('formats d’envoi des formulaires d’identification', [
    'en JSON, comme la page (Inertia)' => ['json'],
    'encodé comme un formulaire HTML' => ['formulaire'],
]);

/**
 * Envoie `$donnees` à `$route` depuis la page `$page`, sous la forme donnée.
 *
 * @param  array<string, mixed>  $donnees
 * @return TestResponse<Response>
 */
function identifiantsHorsDesExceptionsEnvoyer(string $page, string $route, array $donnees, string $format): TestResponse
{
    if ($format === 'json') {
        /** @var TestResponse<Response> $reponse */
        $reponse = test()->from($page)->json('POST', $route, $donnees, [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ]);

        return $reponse;
    }

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from($page)->post($route, $donnees);

    return $reponse;
}

/*
 * Les formulaires où l'on tape son adresse sans lien : la connexion, et la
 * demande d'un lien « mot de passe oublié », qui met l'envoi en file (#1951) ;
 * la file des tests est synchrone, et le job cherche le compte pendant la
 * requête. Pour chacun, la page d'où part l'envoi, sa route, les autres
 * champs, et ce que le corps enregistré en garde.
 */
dataset('formulaires où l’on tape son adresse', [
    'connexion' => [
        'login',
        'login',
        ['password' => 'Un-mot-de-passe-2026!', 'remember' => false],
        ['email' => '[masqué]', 'password' => '[masqué]', 'remember' => false],
    ],
    'mot de passe oublié' => [
        'password.request',
        'password.email',
        [],
        ['email' => '[masqué]'],
    ],
]);

/* Chaque formulaire, sous les deux formes d'envoi. */
it('ne recopie l’adresse tapée ni dans le journal, ni dans le rapport, ni dans l’exception enregistrée', function (string $page, string $route, array $autresChamps, array $corpsGarde, string $format): void {
    /** @var array<string, mixed> $autresChamps */
    Config::set('app.debug', false);
    identifiantsHorsDesExceptionsCouperLaTable('users');

    $traces = identifiantsHorsDesExceptionsTracesDe(static fn (): TestResponse => identifiantsHorsDesExceptionsEnvoyer(
        route($page),
        route($route),
        ['email' => identifiantsHorsDesExceptionsAdresseTapee(), ...$autresChamps],
        $format,
    )->assertInternalServerError());

    $exception = ExceptionEnregistree::query()->sole();
    $lues = [
        'réponse' => (string) $traces['reponse']->getContent(),
        'journal' => $traces['journal'],
        'rapport' => implode("\n", $traces['rapport']),
        ...identifiantsHorsDesExceptionsColonnes($exception),
    ];

    expect($exception->message)->toBe('La base ne répond plus.')
        ->and($exception->path)->toBe(ltrim((string) parse_url(route($route), PHP_URL_PATH), '/'))
        ->and($traces['journal'])->toContain('La base ne répond plus.')
        ->and($traces['rapport'])->toHaveCount(1)
        ->and($exception->body)->toEqual($corpsGarde)
        ->and(array_keys(array_filter($lues, identifiantsHorsDesExceptionsLaissentLire(...))))->toBe([]);
})->with('formulaires où l’on tape son adresse')->with('formats d’envoi des formulaires d’identification');

it('ne recopie l’adresse tapée à la réinitialisation ni dans le journal, ni dans le rapport, ni dans l’exception enregistrée', function (string $format): void {
    Config::set('app.debug', false);
    $compte = User::factory()->create(['email' => 'compte.du-lien@example.org']);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, Password::createToken($compte));
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, 'Un-nouveau-mot-de-passe-2026!', identifiantsHorsDesExceptionsAdresseTapee());
    identifiantsHorsDesExceptionsCouperLaTable('users');

    $traces = identifiantsHorsDesExceptionsTracesDe(static fn (): TestResponse => identifiantsHorsDesExceptionsEnvoyer(
        $lien,
        route('password.store'),
        $formulaire,
        $format,
    )->assertInternalServerError());

    $exception = ExceptionEnregistree::query()->sole();
    $lues = [
        'réponse' => (string) $traces['reponse']->getContent(),
        'journal' => $traces['journal'],
        'rapport' => implode("\n", $traces['rapport']),
        ...identifiantsHorsDesExceptionsColonnes($exception),
    ];

    expect($exception->message)->toBe('La base ne répond plus.')
        ->and($traces['journal'])->toContain('La base ne répond plus.')
        ->and($traces['rapport'])->toHaveCount(1)
        ->and($exception->body)->toMatchArray([
            'email' => '[masqué]',
            'adresse' => '[masqué]',
            'token' => '[masqué]',
            'password' => '[masqué]',
            'password_confirmation' => '[masqué]',
        ])
        // L'en-tête de la page d'où part l'envoi porte le lien lui-même.
        ->and($exception->headers['referer'] ?? null)->toBe('[masqué]')
        ->and(array_keys(array_filter($lues, identifiantsHorsDesExceptionsLaissentLire(...))))->toBe([]);
})->with('formats d’envoi des formulaires d’identification');

/**
 * Ce texte laisse-t-il lire `$secret`, tel quel, encodé dans une URL, ou
 * écrit en JSON (`/` échappé) ?
 */
function identifiantsHorsDesExceptionsPorte(string $texte, string $secret): bool
{
    return str_contains($texte, $secret)
        || str_contains($texte, rawurlencode($secret))
        || str_contains($texte, urlencode($secret))
        || str_contains($texte, str_replace('/', '\/', $secret));
}

/*
 * L'ouverture du lien (#2070) lit le compte avant de montrer le formulaire :
 * une panne à cet instant garde l'exception d'une requête dont le chemin
 * porte le jeton, et l'adresse de la page sa valeur chiffrée. Le chemin
 * enregistré porte « [masqué] » à la place du jeton, comme le paramètre de
 * route, et la valeur chiffrée se masque comme dans le corps de l'envoi. Le
 * Markdown, que Laravel ne remplit qu'en mode debug, recopie le chemin et les
 * paramètres bruts, à dessein (`.ai/rules/models.md`) : il n'est pas lu.
 */
it('ne garde ni le jeton ni l’adresse chiffrée d’un lien de réinitialisation ouvert pendant une panne', function (): void {
    Config::set('app.debug', false);
    $compte = User::factory()->create(['email' => 'compte.du-lien@example.org']);
    $jeton = Password::createToken($compte);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, $jeton);
    $adresseChiffree = FormulaireDeReinitialisation::depuisLeLien($lien, 'sans-objet', 'sans-objet@example.org')['adresse'];
    identifiantsHorsDesExceptionsCouperLaTable('users');

    $traces = identifiantsHorsDesExceptionsTracesDe(static fn (): TestResponse => get($lien)->assertInternalServerError());

    $exception = ExceptionEnregistree::query()->sole();
    $colonnes = identifiantsHorsDesExceptionsColonnes($exception);
    unset($colonnes['markdown']);
    $lues = [
        'réponse' => (string) $traces['reponse']->getContent(),
        'journal' => $traces['journal'],
        'rapport' => implode("\n", $traces['rapport']),
        ...$colonnes,
    ];

    expect($exception->message)->toBe('La base ne répond plus.')
        ->and($exception->path)->toBe('reset-password/[masqué]')
        ->and($exception->route_parameters)->toBe(['token' => '[masqué]'])
        ->and($exception->body)->toBe(['adresse' => '[masqué]'])
        ->and(array_keys(array_filter(
            $lues,
            static fn (string $texte): bool => identifiantsHorsDesExceptionsPorte($texte, $jeton)
                || identifiantsHorsDesExceptionsPorte($texte, $adresseChiffree),
        )))->toBe([]);
});

/**
 * L'instantané du formulaire de connexion du panneau, tel que la page le
 * donne au navigateur.
 */
function identifiantsHorsDesExceptionsInstantaneDuPanneau(): string
{
    $page = (string) get('/backoffice/login')->assertOk()->getContent();

    if (preg_match('/wire:snapshot="([^"]*)"/', $page, $trouve) !== 1) {
        throw new RuntimeException('La page de connexion du panneau ne porte pas d’instantané.');
    }

    return html_entity_decode($trouve[1], ENT_QUOTES);
}

/**
 * Envoie le formulaire de connexion du panneau comme Livewire l'envoie : la
 * saisie sous `updates`, en chemins à points, l'état du composant dans
 * l'instantané, puis l'appel de `authenticate`.
 *
 * @return TestResponse<Response>
 */
function identifiantsHorsDesExceptionsConnecterAuPanneau(string $instantane, string $adresse, string $motDePasse): TestResponse
{
    $adresseDesMisesAJour = app(HandleRequests::class)->getUpdateUri();

    if (! is_string($adresseDesMisesAJour)) {
        throw new RuntimeException('Livewire ne donne pas l’adresse de ses mises à jour.');
    }

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->postJson($adresseDesMisesAJour, [
        'components' => [[
            'snapshot' => $instantane,
            'updates' => ['data.email' => $adresse, 'data.password' => $motDePasse],
            'calls' => [['method' => 'authenticate', 'params' => [], 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1']);

    return $reponse;
}

/*
 * Le panneau passe par Livewire, qui envoie la saisie sous des chemins à
 * points (`data.password`) : la clef `password` ne les reconnaissait pas, et
 * l'exception d'une connexion au panneau gardait le mot de passe de
 * l'administrateur en clair. L'instantané du composant, du JSON écrit en
 * texte, porte en plus la saisie d'un essai refusé : le premier envoi, refusé,
 * l'y met, et le second, en panne, le renvoie.
 */
it('ne garde en clair ni le mot de passe ni l’adresse d’une connexion au panneau, essai refusé compris', function (): void {
    Admin::factory()->create(['email' => 'administrateur.du-panneau@example.org']);

    $refus = identifiantsHorsDesExceptionsConnecterAuPanneau(
        identifiantsHorsDesExceptionsInstantaneDuPanneau(),
        'administrateur.du-panneau@example.org',
        'Premier-essai-refuse-2026!',
    )->assertOk();
    $instantaneApresLeRefus = $refus->json('components.0.snapshot');
    expect($instantaneApresLeRefus)->toBeString()->toContain('Premier-essai-refuse-2026!');

    identifiantsHorsDesExceptionsCouperLaTable('admins');
    identifiantsHorsDesExceptionsConnecterAuPanneau(
        is_string($instantaneApresLeRefus) ? $instantaneApresLeRefus : '',
        identifiantsHorsDesExceptionsAdresseTapee(),
        'Second-essai-en-panne-2026!',
    )->assertInternalServerError();

    $exception = ExceptionEnregistree::query()->sole();
    $colonnes = identifiantsHorsDesExceptionsColonnes($exception);
    $corps = $colonnes['body'] ?? '';

    expect($exception->message)->toBe('La base ne répond plus.')
        ->and(data_get($exception->body, 'components.0.updates'))->toBe(['data.email' => '[masqué]', 'data.password' => '[masqué]'])
        ->and($corps)->not->toContain('Premier-essai-refuse-2026!')
        ->and($corps)->not->toContain('administrateur.du-panneau')
        ->and(array_keys(array_filter(
            $colonnes,
            static fn (string $texte): bool => str_contains($texte, 'Second-essai-en-panne-2026!')
                || str_contains($texte, 'Premier-essai-refuse-2026!')
                || identifiantsHorsDesExceptionsLaissentLire($texte),
        )))->toBe([]);
});

/*
 * Une authentification HTTP : Symfony décode l'en-tête `Authorization` et en
 * tire d'autres, que la requête range parmi ses en-têtes : `php-auth-user` et
 * `php-auth-pw` pour Basic, l'identifiant et le mot de passe en clair,
 * `php-auth-digest` pour Digest, l'en-tête recopié. Ils se masquent comme
 * lui (#PR). Le Markdown, que Laravel ne remplit qu'en mode debug, recopie
 * les en-têtes bruts, à dessein (`.ai/rules/models.md`) : il n'est pas lu.
 */
it('masque les en-têtes qu’une authentification HTTP tire de l’en-tête Authorization', function (string $entete, array $masques): void {
    /** @var list<string> $masques */
    Route::post('/_boum', static function (): never {
        throw new RuntimeException('boum');
    });

    test()->withHeader('Authorization', $entete)->post('/_boum')->assertInternalServerError();

    $exception = ExceptionEnregistree::query()->sole();
    $colonnes = identifiantsHorsDesExceptionsColonnes($exception);
    unset($colonnes['markdown']);

    expect($exception->headers)->toMatchArray(array_fill_keys($masques, '[masqué]'))
        ->and(array_keys(array_filter(
            $colonnes,
            static fn (string $texte): bool => str_contains($texte, 'Mot-De-Passe-Http-2026') || str_contains($texte, 'titulaire.http'),
        )))->toBe([]);
})->with([
    'Basic' => [
        'Basic '.base64_encode('titulaire.http@example.org:Mot-De-Passe-Http-2026'),
        ['authorization', 'php-auth-user', 'php-auth-pw'],
    ],
    'Digest' => [
        'Digest username="titulaire.http@example.org", realm="gym", nonce="n", uri="/_boum", response="Mot-De-Passe-Http-2026"',
        ['authorization', 'php-auth-digest'],
    ],
]);
