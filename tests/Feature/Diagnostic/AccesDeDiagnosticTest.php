<?php

declare(strict_types=1);

use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\PorteeDeDiagnostic;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Models\User;
use App\Notifications\AlerteDeDiagnostic;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\NullHandler;
use Monolog\LogRecord;
use Spatie\Permission\Models\Role;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\call;
use function Pest\Laravel\get;

/*
 * La garde et la route d'état de l'accès de diagnostic (#2032) : chaque refus
 * d'avant le jeton répond comme un chemin inconnu, la portée, le débit, le
 * journal d'audit, l'écriture d'usage. Aucun jeton n'est écrit en clair ici :
 * ils se composent à l'exécution, et la phrase de la note vient de sa
 * constante.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    config()->set('logging.channels.diagnostic', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    config()->set('logging.channels.diagnostic_refus', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    Cache::flush();
    Notification::fake();

    $lignes = accesDeDiagnosticLesLignes();
    $lignes->exchangeArray([]);
    Event::listen(MessageLogged::class, function (MessageLogged $evenement) use ($lignes): void {
        if (str_starts_with($evenement->message, 'diagnostic.')) {
            $lignes->append(['message' => $evenement->message, 'contexte' => $evenement->context]);
        }
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Les lignes que le diagnostic a écrites dans ses canaux pendant le test.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function accesDeDiagnosticLesLignes(): ArrayObject
{
    /** @var ArrayObject<int, array<string, mixed>> $lignes */
    static $lignes = new ArrayObject();

    return $lignes;
}

final class GestionnaireQuiNEcritPas extends AbstractProcessingHandler
{
    #[\Override]
    protected function write(LogRecord $record): void
    {
        throw new RuntimeException('Le disque est plein.');
    }
}

/**
 * Un jeton actif, créé par un super administrateur qui a la capacité.
 *
 * @param  list<string>  $portees
 * @param  array<string, mixed>  $attributs
 * @return array{jeton: JetonDeDiagnostic, en_clair: string, admin: Admin}
 */
function accesDeDiagnosticUnJeton(array $portees = ['sante', 'exceptions'], array $attributs = []): array
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $identifiant = Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT));
    $secret = Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET);

    $jeton = JetonDeDiagnostic::factory()->create([
        'identifiant' => $identifiant,
        'empreinte' => JetonDeDiagnostic::empreinteDe($secret),
        'portees' => $portees,
        'cree_par_admin_id' => $admin->id,
        ...$attributs,
    ]);

    return ['jeton' => $jeton, 'en_clair' => JetonDeDiagnostic::composer($identifiant, $secret), 'admin' => $admin];
}

/**
 * @return TestResponse<Response>
 */
function accesDeDiagnosticAppeler(string $uri, ?string $entete, bool $json = true): TestResponse
{
    $entetes = ['Accept' => $json ? 'application/json' : 'text/html'];

    if ($entete !== null) {
        $entetes['Authorization'] = $entete;
    }

    return get($uri, $entetes);
}

function accesDeDiagnosticBearer(string $enClair): string
{
    return 'Bearer '.$enClair;
}

/**
 * Tout ce qu'une réponse dit, hors `Date` : le statut, le corps, chaque en-tête.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array{statut: int, corps: string, entetes: array<string, list<string|null>>}
 */
function accesDeDiagnosticTout(TestResponse $reponse): array
{
    /** @var array<string, list<string|null>> $entetes */
    $entetes = $reponse->headers->allPreserveCase();
    unset($entetes['Date'], $entetes['date']);
    ksort($entetes);

    return ['statut' => $reponse->getStatusCode(), 'corps' => (string) $reponse->getContent(), 'entetes' => $entetes];
}

/**
 * Un jeton de la portée `sante`.
 *
 * @param  array<string, mixed>  $attributs
 * @return array{jeton: JetonDeDiagnostic, en_clair: string, admin: Admin}
 */
function accesDeDiagnosticValide(array $attributs = []): array
{
    return accesDeDiagnosticUnJeton(['sante'], $attributs);
}

/**
 * La situation de chaque cause de refus d'avant le jeton : l'adresse et
 * l'en-tête que la requête présente.
 *
 * @return array{0: string, 1: ?string}
 */
function accesDeDiagnosticSituation(string $cause): array
{
    $uri = '/diagnostic/v1/etat';
    $valide = accesDeDiagnosticValide(...);

    return match ($cause) {
        'coupé' => (function () use ($uri, $valide): array {
            config()->set('app.diagnostic_enabled', false);

            return [$uri, accesDeDiagnosticBearer($valide()['en_clair'])];
        })(),
        'coupé par une valeur qui n’est pas un booléen' => (function () use ($uri, $valide): array {
            config()->set('app.diagnostic_enabled', 'true');

            return [$uri, accesDeDiagnosticBearer($valide()['en_clair'])];
        })(),
        'http en production' => (function () use ($uri, $valide): array {
            app()['env'] = 'production';

            return [$uri, accesDeDiagnosticBearer($valide()['en_clair'])];
        })(),
        'absent' => [$uri, null],
        'sans le mot Bearer' => [$uri, $valide()['en_clair']],
        'avec un autre schéma' => [$uri, 'Basic '.$valide()['en_clair']],
        'avec le mot en minuscules' => [$uri, 'bearer '.$valide()['en_clair']],
        'suivi d’une espace' => [$uri, accesDeDiagnosticBearer($valide()['en_clair']).' '],
        'suivi d’un saut de ligne' => [$uri, accesDeDiagnosticBearer($valide()['en_clair'])."\n"],
        'au préfixe changé' => [$uri, 'Bearer gtdiaz_'.substr($valide()['en_clair'], 7)],
        'au secret trop court' => [$uri, substr(accesDeDiagnosticBearer($valide()['en_clair']), 0, -1)],
        'au secret trop long' => [$uri, accesDeDiagnosticBearer($valide()['en_clair']).'a'],
        'à l’identifiant en majuscules' => [$uri, 'Bearer '.JetonDeDiagnostic::PREFIXE.Str::upper(Str::random(12)).'_'.Str::random(48)],
        'inconnu' => [$uri, 'Bearer '.JetonDeDiagnostic::composer(Str::lower(Str::random(12)), Str::random(48))],
        'au secret faux' => (function () use ($uri, $valide): array {
            $jeton = $valide()['jeton'];

            return [$uri, 'Bearer '.JetonDeDiagnostic::composer($jeton->identifiant, Str::random(48))];
        })(),
        'expiré' => [$uri, accesDeDiagnosticBearer($valide(['expire_le' => now()->subSecond()])['en_clair'])],
        'révoqué' => [$uri, accesDeDiagnosticBearer($valide(['revoque_le' => now()])['en_clair'])],
        'dont le créateur est supprimé' => (function () use ($uri, $valide): array {
            $cree = $valide();
            $cree['admin']->delete();

            return [$uri, accesDeDiagnosticBearer($cree['en_clair'])];
        })(),
        'dont le créateur a perdu la capacité' => (function () use ($uri, $valide): array {
            $cree = $valide();
            $cree['admin']->syncRoles([]);

            return [$uri, accesDeDiagnosticBearer($cree['en_clair'])];
        })(),
        'présenté dans l’adresse' => (function () use ($valide): array {
            $enClair = $valide()['en_clair'];

            return ['/diagnostic/v1/etat?token='.$enClair.'&access_token='.$enClair.'&jeton='.$enClair, null];
        })(),
        'dont la route ne déclare aucune portée' => (function () use ($valide): array {
            Route::get('/diagnostic/v1/sans-portee', static fn (): string => 'x')
                ->middleware([ExigeUnJetonDeDiagnostic::class])
                ->name('diagnostic.sans-portee');

            return ['/diagnostic/v1/sans-portee', accesDeDiagnosticBearer($valide()['en_clair'])];
        })(),
        default => throw new InvalidArgumentException($cause),
    };
}

dataset('refus avant le jeton', [
    'coupé', 'coupé par une valeur qui n’est pas un booléen', 'http en production', 'absent', 'sans le mot Bearer',
    'avec un autre schéma', 'avec le mot en minuscules', 'suivi d’une espace', 'suivi d’un saut de ligne',
    'au préfixe changé', 'au secret trop court', 'au secret trop long', 'à l’identifiant en majuscules', 'inconnu',
    'au secret faux', 'expiré', 'révoqué', 'dont le créateur est supprimé', 'dont le créateur a perdu la capacité',
    'présenté dans l’adresse', 'dont la route ne déclare aucune portée',
]);

it('répond à chaque refus d’avant le jeton comme à un chemin inconnu, en JSON comme en HTML', function (string $cause, bool $json): void {
    [$uri, $entete] = accesDeDiagnosticSituation($cause);

    $inconnu = accesDeDiagnosticTout(accesDeDiagnosticAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null, $json));
    $refuse = accesDeDiagnosticTout(accesDeDiagnosticAppeler($uri, $entete, $json));

    expect($inconnu['statut'])->toBe(404)
        ->and($refuse)->toBe($inconnu)
        ->and($refuse['entetes'])->not->toHaveKeys(['WWW-Authenticate', 'Retry-After', 'Set-Cookie', 'Server-Timing']);
})->with('refus avant le jeton')->with([true, false]);

it('répond comme un chemin inconnu à chaque forme inattendue de l’en-tête, jamais 500', function (string $forme): void {
    $inconnu = accesDeDiagnosticTout(accesDeDiagnosticAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));
    $valeur = Tests\Support\EntetesLus::formes(accesDeDiagnosticBearer(accesDeDiagnosticUnJeton()['en_clair']))[$forme];

    $reponse = call('GET', '/diagnostic/v1/etat', server: Tests\Support\RequetesMalFormees::serveur(['Authorization' => $valeur], null) + ['HTTP_ACCEPT' => 'application/json']);

    expect($reponse->getStatusCode())->toBe(404)
        ->and(accesDeDiagnosticTout($reponse))->toBe($inconnu);
})->with(array_keys(Tests\Support\EntetesLus::formes('valeur')));

it('n’écrit rien en base pour un refus, et n’en dit rien à un jeton inconnu', function (string $cause): void {
    [$uri, $entete] = accesDeDiagnosticSituation($cause);
    $ecritures = new ArrayObject();
    DB::listen(function ($requete) use ($ecritures): void {
        if (! str_starts_with(strtolower(ltrim($requete->sql)), 'select')) {
            $ecritures->append($requete->sql);
        }
    });

    accesDeDiagnosticAppeler($uri, $entete);

    expect($ecritures->getArrayCopy())->toBe([]);
})->with('refus avant le jeton');

it('refuse comme un chemin inconnu quand la base ne répond pas', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $inconnu = accesDeDiagnosticTout(accesDeDiagnosticAppeler('/diagnostic/v1/ce-chemin-n-existe-pas', null));
    config()->set('testing.base_en_panne', true);
    DB::connection()->beforeExecuting(static function (): void {
        if (config('testing.base_en_panne') === true) {
            throw new PDOException('La base ne répond pas.');
        }
    });

    $refuse = accesDeDiagnosticTout(accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair'])));
    config()->set('testing.base_en_panne', false);

    expect($refuse)->toBe($inconnu);
});

it('lit l’en-tête même quand l’accès est coupé, pour que la lecture ne dépende pas de l’interrupteur', function (): void {
    config()->set('app.diagnostic_enabled', false);
    $cree = accesDeDiagnosticUnJeton();

    $this->get('/diagnostic/v1/etat', ['Authorization' => accesDeDiagnosticBearer($cree['en_clair'])])->assertNotFound();

    expect($cree['jeton']->fresh()?->derniere_utilisation_le)->toBeNull();
});

it('sert l’état à tout jeton, avec l’enveloppe, la note et les en-têtes d’un accès privé', function (): void {
    $cree = accesDeDiagnosticUnJeton(['sante']);

    $reponse = accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair']));

    $reponse->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Vary', 'Authorization')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeaderMissing('Set-Cookie')
        ->assertHeaderMissing('Access-Control-Allow-Origin')
        ->assertHeaderMissing('Server-Timing')
        ->assertJsonPath('note', EnveloppeDeDiagnostic::NOTE)
        ->assertJsonPath('donnees.jeton.fin', substr($cree['jeton']->identifiant, -4))
        ->assertJsonPath('donnees.jeton.portees', ['sante'])
        ->assertJsonStructure(['donnees' => ['application' => ['version', 'revision', 'environnement'], 'jeton' => ['fin', 'portees', 'expire_le'], 'versions', 'sante', 'taches_planifiees'], 'suivant', 'tronque', 'echantillon', 'genere_le', 'note'])
        ->assertJsonMissingPath('donnees.exceptions');

    expect((string) $reponse->getContent())->not->toContain($cree['en_clair'])
        ->and((string) $reponse->getContent())->not->toContain($cree['jeton']->identifiant);
});

it('répond à HEAD, sans corps', function (): void {
    $cree = accesDeDiagnosticUnJeton();

    $reponse = call('HEAD', '/diagnostic/v1/etat', server: ['HTTP_AUTHORIZATION' => accesDeDiagnosticBearer($cree['en_clair'])]);

    $reponse->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect((string) $reponse->getContent())->toBe('');
});

it('sert en HTTPS en production', function (): void {
    app()['env'] = 'production';
    $cree = accesDeDiagnosticUnJeton();

    $this->get('https://localhost/diagnostic/v1/etat', ['Authorization' => accesDeDiagnosticBearer($cree['en_clair'])])->assertOk();
});

it('ne montre une source qu’au jeton qui en a la portée, et reste sous 8 Kio même source pleine', function (): void {
    $cree = accesDeDiagnosticUnJeton(array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, PorteeDeDiagnostic::cases()));
    $compte = User::factory()->create();

    foreach (range(1, 60) as $rang) {
        DB::table('filament_exceptions_table')->insert([
            'type' => 'RuntimeException', 'code' => '0', 'message' => 'texte venu de l’extérieur', 'file' => base_path('app/Models/User.php'),
            'line' => $rang, 'trace' => '[]', 'method' => 'GET', 'path' => '/dashboard', 'created_at' => now()->subMinutes($rang), 'updated_at' => now(),
        ]);
        DB::table('erreurs_navigateur')->insert([
            'user_id' => $compte->id, 'type' => 'error', 'message' => 'texte venu de l’extérieur', 'url' => 'https://example.org/page', 'agent' => 'Mozilla',
            'empreinte' => md5((string) $rang), 'created_at' => now()->subMinutes($rang), 'updated_at' => now(),
        ]);
    }

    $reponse = accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair']));

    $reponse->assertOk()
        ->assertJsonCount(5, 'donnees.exceptions.groupes')
        ->assertJsonCount(3, 'donnees.erreurs_navigateur.groupes')
        ->assertJsonPath('donnees.exceptions.tronque', true)
        ->assertJsonPath('donnees.erreurs_navigateur.tronque', true)
        ->assertJsonPath('tronque', true)
        ->assertJsonStructure(['donnees' => ['files' => ['horizon', 'echecs']]]);

    expect(strlen((string) $reponse->getContent()))->toBeLessThan(8 * 1024)
        ->and((string) $reponse->getContent())->not->toContain('texte venu de l’extérieur')
        ->and((string) $reponse->getContent())->not->toContain('"non_fiable":');
});

it('refuse en 403 une route dont la portée manque, et la sert à qui l’a', function (): void {
    $sansLaPortee = accesDeDiagnosticUnJeton(['exceptions']);
    $avecLaPortee = accesDeDiagnosticUnJeton(['sante']);

    $refus = accesDeDiagnosticAppeler('/diagnostic/v1/sante', accesDeDiagnosticBearer($sansLaPortee['en_clair']));
    $servi = accesDeDiagnosticAppeler('/diagnostic/v1/sante', accesDeDiagnosticBearer($avecLaPortee['en_clair']));

    $refus->assertForbidden()
        ->assertExactJson(['erreur' => 'portee-non-accordee', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex');
    $servi->assertOk()->assertJsonStructure(['donnees' => ['sante' => ['dernier_passage_le', 'age_en_secondes', 'controles'], 'versions']]);
    expect((string) $servi->getContent())->not->toContain('"non_fiable":');
});

it('range le texte de la santé sous non_fiable pour le seul jeton qui a le texte brut', function (): void {
    $cree = accesDeDiagnosticUnJeton(['sante', 'texte-brut']);

    $reponse = accesDeDiagnosticAppeler('/diagnostic/v1/sante', accesDeDiagnosticBearer($cree['en_clair']));

    $reponse->assertOk();
    expect($reponse->json('note'))->toBe(EnveloppeDeDiagnostic::NOTE);
});

it('refuse la trente et unième requête de la minute en 429, avec Retry-After, et alerte une fois', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = accesDeDiagnosticBearer($cree['en_clair']);

    foreach (range(1, 30) as $rang) {
        accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    }

    $refus = accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete);
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertStatus(429);

    $refus->assertStatus(429)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('erreur', 'debit-depasse')
        ->assertHeader('Cache-Control', 'no-store, private');

    Notification::assertSentOnDemandTimes(AlerteDeDiagnostic::class, 2);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, fn (AlerteDeDiagnostic $alerte): bool => $alerte->evenement === EvenementDAlerteDeDiagnostic::DebitDepasse);

    Carbon::setTestNow(now()->addSeconds(61));
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
});

it('refuse la 301e requête de l’heure, minute après minute', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = accesDeDiagnosticBearer($cree['en_clair']);

    foreach (range(1, 10) as $minute) {
        foreach (range(1, 30) as $rang) {
            accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
        }

        Carbon::setTestNow(now()->addSeconds(61));
    }

    $refus = accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete);

    $refus->assertStatus(429);
    expect((int) $refus->headers->get('Retry-After'))->toBeGreaterThan(60)->toBeLessThanOrEqual(3600);
});

it('compte le débit par jeton : un jeton à bout de souffle ne coupe pas l’autre', function (): void {
    $premier = accesDeDiagnosticUnJeton();
    $second = accesDeDiagnosticUnJeton();

    foreach (range(1, 31) as $rang) {
        accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($premier['en_clair']));
    }

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($premier['en_clair']))->assertStatus(429);
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($second['en_clair']))->assertOk();
});

it('ne compte aucun refus contre le débit d’un jeton', function (): void {
    $cree = accesDeDiagnosticUnJeton(['exceptions']);
    $entete = accesDeDiagnosticBearer($cree['en_clair']);

    foreach (range(1, 40) as $rang) {
        accesDeDiagnosticAppeler('/diagnostic/v1/sante', $entete)->assertForbidden();
    }

    foreach (range(1, 30) as $rang) {
        accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    }
});

it('alerte quand un jeton révoqué ou expiré est présenté, et pas pour un secret faux', function (): void {
    $revoque = accesDeDiagnosticUnJeton(attributs: ['revoque_le' => now()]);
    $faux = accesDeDiagnosticUnJeton(attributs: ['revoque_le' => now()]);

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', 'Bearer '.JetonDeDiagnostic::composer($faux['jeton']->identifiant, Str::random(48)));
    Notification::assertNothingSent();

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($revoque['en_clair']));

    Notification::assertSentOnDemandTimes(AlerteDeDiagnostic::class, 1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, fn (AlerteDeDiagnostic $alerte): bool => $alerte->evenement === EvenementDAlerteDeDiagnostic::JetonInvalidePresente && $alerte->jetons === [substr($revoque['jeton']->identifiant, -4)]);
});

it('n’écrit en base l’usage du jeton qu’une fois toutes les dix minutes, et alerte au premier usage', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = accesDeDiagnosticBearer($cree['en_clair']);
    $ecritures = new ArrayObject();
    DB::listen(function ($requete) use ($ecritures): void {
        if (str_starts_with(strtolower(ltrim($requete->sql)), 'update `jetons_de_diagnostic`')) {
            $ecritures->append($requete->sql);
        }
    });

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    Carbon::setTestNow(now()->addMinutes(9));
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();

    expect($ecritures)->toHaveCount(1)
        ->and($cree['jeton']->fresh()?->derniere_adresse)->toBe('127.0.0.1');

    Carbon::setTestNow(now()->addMinutes(2));
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();

    expect($ecritures)->toHaveCount(2);
    Notification::assertSentOnDemandTimes(AlerteDeDiagnostic::class, 1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, fn (AlerteDeDiagnostic $alerte): bool => $alerte->evenement === EvenementDAlerteDeDiagnostic::PremierUsage);
});

it('écrit une ligne d’audit par appel servi, sans le jeton', function (): void {
    $cree = accesDeDiagnosticUnJeton();

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair']))->assertOk();

    $appels = array_values(array_filter(accesDeDiagnosticLesLignes()->getArrayCopy(), static fn (array $ligne): bool => $ligne['message'] === 'diagnostic.appel'));

    expect($appels)->toHaveCount(1)
        ->and($appels[0]['contexte'])->toMatchArray([
            'jeton' => $cree['jeton']->identifiant,
            'nom' => 'Agent de maintenance',
            'route' => 'diagnostic.etat',
            'parametres' => [],
            'statut' => 200,
            'adresse' => '127.0.0.1',
        ])
        ->and(data_get($appels[0], 'contexte.octets'))->toBeInt()->toBeGreaterThan(0)
        ->and(data_get($appels[0], 'contexte.elements'))->toBeInt()->toBeGreaterThan(0)
        ->and(data_get($appels[0], 'contexte.duree_ms'))->toBeInt()
        ->and((string) json_encode($appels[0]))->not->toContain(substr($cree['en_clair'], 20));
});

it('audite aussi le 403 et le 429, qui sont des réponses du jeton', function (): void {
    $cree = accesDeDiagnosticUnJeton(['exceptions']);

    accesDeDiagnosticAppeler('/diagnostic/v1/sante', accesDeDiagnosticBearer($cree['en_clair']))->assertForbidden();

    expect(collect(accesDeDiagnosticLesLignes()->getArrayCopy())->pluck('contexte.statut')->all())->toBe([403]);
});

it('rend une 500 sans donnée quand le journal d’audit ne s’écrit pas', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    config()->set('logging.channels.diagnostic', ['driver' => 'monolog', 'handler' => GestionnaireQuiNEcritPas::class]);

    $reponse = accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair']));

    $reponse->assertStatus(500)
        ->assertExactJson(['erreur' => 'erreur-interne', 'note' => EnveloppeDeDiagnostic::NOTE])
        ->assertHeader('Cache-Control', 'no-store, private');
    expect((string) $reponse->getContent())->not->toContain('disque')
        ->and((string) $reponse->getContent())->not->toContain('donnees');
});

it('rend une 500 sans détail quand le cache ne répond pas, même en débogage', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    config()->set('app.debug', true);
    config()->set('cache.default', 'inexistant');
    config()->set('cache.stores.inexistant', ['driver' => 'inexistant']);

    $reponse = accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($cree['en_clair']));

    $reponse->assertStatus(500)->assertExactJson(['erreur' => 'erreur-interne', 'note' => EnveloppeDeDiagnostic::NOTE]);
});

it('limite les refus au journal : une ligne par identifiant connu et par minute, une synthèse par minute pour les autres', function (): void {
    $revoque = accesDeDiagnosticUnJeton(attributs: ['revoque_le' => now()]);

    foreach (range(1, 3) as $rang) {
        accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($revoque['en_clair']));
        accesDeDiagnosticAppeler('/diagnostic/v1/etat', null);
    }

    $connus = array_values(array_filter(accesDeDiagnosticLesLignes()->getArrayCopy(), static fn (array $ligne): bool => $ligne['message'] === 'diagnostic.refus'));
    $syntheses = array_values(array_filter(accesDeDiagnosticLesLignes()->getArrayCopy(), static fn (array $ligne): bool => $ligne['message'] === 'diagnostic.refus.synthese'));

    expect($connus)->toHaveCount(1)
        ->and($connus[0]['contexte'])->toMatchArray(['jeton' => $revoque['jeton']->identifiant, 'cause' => 'revoque'])
        ->and($syntheses)->toHaveCount(1);

    Carbon::setTestNow(now()->addMinute());
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', accesDeDiagnosticBearer($revoque['en_clair']));
    accesDeDiagnosticAppeler('/diagnostic/v1/etat', null);
    $suite = collect(accesDeDiagnosticLesLignes()->getArrayCopy());

    expect($suite->where('message', 'diagnostic.refus'))->toHaveCount(2)
        ->and(data_get($suite->where('message', 'diagnostic.refus.synthese')->last(), 'contexte.refus_anonymes'))->toBe(3);
});

it('ne tient ni une session web ou admin, ni un jeton Sanctum, pour un jeton de diagnostic', function (): void {
    $compte = User::factory()->create();
    $admin = FilamentAdminPanel::admin();

    $this->actingAs($compte)->get('/diagnostic/v1/etat', ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($compte, 'sanctum')->get('/diagnostic/v1/etat', ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($admin, 'admin')->get('/diagnostic/v1/etat', ['Accept' => 'application/json'])->assertNotFound();
});

it('ne laisse le jeton de diagnostic rien ouvrir d’autre', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = ['Authorization' => accesDeDiagnosticBearer($cree['en_clair']), 'Accept' => 'application/json'];

    $this->postJson('/api/v1/workouts', [], $entete)->assertUnauthorized();
    $this->get('/backoffice', $entete)->assertUnauthorized();
    $this->get('/dashboard', $entete)->assertUnauthorized();
});

it('ne sert rien en écriture', function (string $methode): void {
    $cree = accesDeDiagnosticUnJeton();

    call($methode, '/diagnostic/v1/etat', server: ['HTTP_AUTHORIZATION' => accesDeDiagnosticBearer($cree['en_clair']), 'HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(405);

    expect(accesDeDiagnosticLesLignes())->toHaveCount(0);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('relit la ligne du jeton à chaque requête : révoqué entre deux appels, il est refusé', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = accesDeDiagnosticBearer($cree['en_clair']);

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    $cree['jeton']->forceFill(['revoque_le' => now()])->save();

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertNotFound();
});

it('relit l’interrupteur à chaque requête', function (): void {
    $cree = accesDeDiagnosticUnJeton();
    $entete = accesDeDiagnosticBearer($cree['en_clair']);

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertOk();
    config()->set('app.diagnostic_enabled', false);

    accesDeDiagnosticAppeler('/diagnostic/v1/etat', $entete)->assertNotFound();
});
