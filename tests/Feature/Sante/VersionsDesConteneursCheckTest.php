<?php

declare(strict_types=1);

use App\Support\Sante\AnnonceDeVersion;
use App\Support\Sante\VersionsDesConteneursCheck;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\WorkerStarting as DemarrageDUnTravailleurDeFile;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Octane\Events\WorkerStarting as DemarrageDUnTravailleurOctane;

/*
 * Le scheduler de production a tourné des semaines sur une vieille image sans que
 * rien ne le dise (#1813) : un conteneur garde l'image avec laquelle il a été
 * créé, et retélécharger `:v1` ne le met pas à jour. Chaque conteneur dit
 * désormais au démarrage, dans le cache, quelle image il exécute, et la page
 * « Santé » compare. Le cache de production évince les clés qu'il sert le
 * moins (#1930) : chaque conteneur se réannonce donc régulièrement.
 */

/**
 * Le conteneur donné s'annonce à la date donnée, avec la version donnée.
 */
function versionsAnnonceePar(string $conteneur, string $version, string $revision, string $le): void
{
    Carbon::setTestNow($le);
    Config::set('app.version', $version);
    Config::set('app.revision', $revision);

    AnnonceDeVersion::annoncer($conteneur);
}

/**
 * Les trois évènements qui marquent le démarrage d'un conteneur : un
 * travailleur Octane dans app, un travailleur de file dans worker, une tâche
 * planifiée dans scheduler.
 */
function versionsDemarrageDesTroisConteneurs(): void
{
    event(new DemarrageDUnTravailleurOctane(app()));
    event(new DemarrageDUnTravailleurDeFile('redis', 'default', new WorkerOptions()));
    event(new ScheduledTaskStarting(app(Schedule::class)->command('inspire')));
}

/**
 * Le contrôle, jugé comme en production.
 */
function versionsControleEnProduction(): VersionsDesConteneursCheck
{
    app()->detectEnvironment(fn (): string => 'production');

    return VersionsDesConteneursCheck::new();
}

/**
 * Une vraie file, servie par un worker comme celle d'Horizon en production,
 * mais rangée dans une base SQLite en mémoire : un job y attend qu'un worker
 * le traite, au lieu de s'exécuter sur place comme sous la file `sync` des
 * tests.
 */
function versionsFileServieParUnWorker(): void
{
    Config::set('database.connections.file_des_versions', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    Schema::connection('file_des_versions')->create('jobs', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    Config::set('queue.connections.file_des_versions', [
        'driver' => 'database',
        'connection' => 'file_des_versions',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    Config::set('queue.default', 'file_des_versions');
}

/**
 * Le worker traite le job suivant de la file « default ».
 */
function versionsLeWorkerTraiteLeJobSuivant(): void
{
    /** @var Worker $worker */
    $worker = app('queue.worker');

    $worker->runNextJob('file_des_versions', 'default', new WorkerOptions());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('ne connaît pas sa version hors d’une image construite par la CI', function (): void {
    expect(config('app.version'))->toBe('dev')
        ->and(config('app.revision'))->toBe('inconnue');
});

it('fait annoncer à chaque conteneur, à son démarrage, la version qu’il exécute', function (): void {
    Carbon::setTestNow('2026-10-02 14:00:00');
    Config::set('app.version', 'v1.5.20');
    Config::set('app.revision', '8bb6b640acd728d1733b64de34f1aafc5897ae8f');

    versionsDemarrageDesTroisConteneurs();

    foreach (AnnonceDeVersion::CONTENEURS as $conteneur) {
        expect(AnnonceDeVersion::lue($conteneur))->toBe([
            'version' => 'v1.5.20',
            'revision' => '8bb6b640acd728d1733b64de34f1aafc5897ae8f',
            'depuis' => '2026-10-02T14:00:00+02:00',
            'le' => '2026-10-02T14:00:00+02:00',
        ], $conteneur);
    }
});

/*
 * Le scheduler s'annonce chaque minute : une écriture SQL par minute coûterait
 * 350 ms à 1,7 s en production (#1668). Les annonces vivent dans le cache,
 * Redis en production.
 */
it('annonce sans rien lire ni écrire en base', function (): void {
    DB::enableQueryLog();

    foreach (AnnonceDeVersion::CONTENEURS as $conteneur) {
        AnnonceDeVersion::annoncer($conteneur);
    }

    expect(DB::getQueryLog())->toBe([])
        ->and(AnnonceDeVersion::lue('scheduler'))->toBeArray();
});

it('garde la date de la première annonce tant que la version ne change pas', function (): void {
    versionsAnnonceePar('scheduler', 'v1.5.12', 'c6c53a9c', '2026-09-06 22:15:00');
    versionsAnnonceePar('scheduler', 'v1.5.12', 'c6c53a9c', '2026-10-02 13:59:00');

    expect(AnnonceDeVersion::lue('scheduler'))->toMatchArray([
        'depuis' => '2026-09-06T22:15:00+02:00',
        'le' => '2026-10-02T13:59:00+02:00',
    ]);

    versionsAnnonceePar('scheduler', 'v1.5.20', '8bb6b640', '2026-10-02 14:01:00');

    expect(AnnonceDeVersion::lue('scheduler'))->toMatchArray([
        'version' => 'v1.5.20',
        'depuis' => '2026-10-02T14:01:00+02:00',
    ]);
});

/*
 * Un écouteur qui lève au démarrage empêcherait Octane de servir et Horizon
 * de travailler : une panne de Redis ne doit coûter que l'annonce.
 */
it('n’empêche aucun conteneur de démarrer quand le cache est en panne', function (): void {
    Cache::shouldReceive('get')->atLeast()->once()->andThrow(new RuntimeException('Redis injoignable'));
    Cache::shouldReceive('forever')->andThrow(new RuntimeException('Redis injoignable'));

    $poursuivis = [];

    foreach ([DemarrageDUnTravailleurOctane::class, DemarrageDUnTravailleurDeFile::class, ScheduledTaskStarting::class] as $evenement) {
        Event::listen($evenement, function () use (&$poursuivis, $evenement): void {
            $poursuivis[] = $evenement;
        });
    }

    versionsDemarrageDesTroisConteneurs();

    expect($poursuivis)->toBe([DemarrageDUnTravailleurOctane::class, DemarrageDUnTravailleurDeFile::class, ScheduledTaskStarting::class]);
});

/*
 * Horizon garde ses processus indéfiniment : annoncée à leur seul démarrage,
 * l'annonce du worker, une fois évincée, ne revenait plus (#1930). Le
 * battement de la file, que le planificateur envoie chaque minute, passe par
 * le worker.
 */
it('fait réannoncer le worker à chaque battement de la file : une annonce évincée du cache revient', function (): void {
    versionsFileServieParUnWorker();
    Carbon::setTestNow('2026-10-02 13:00:00');
    Config::set('app.version', 'v1.5.20');
    Config::set('app.revision', '8bb6b640acd728d1733b64de34f1aafc5897ae8f');
    event(new DemarrageDUnTravailleurDeFile('file_des_versions', 'default', new WorkerOptions()));

    Cache::forget('sante:version:worker');
    Carbon::setTestNow('2026-10-02 14:07:00');
    Artisan::call('health:queue-check-heartbeat');

    // Le battement attend dans la file : l'envoyer n'est pas l'annonce.
    expect(AnnonceDeVersion::lue('worker'))->toBeNull();

    versionsLeWorkerTraiteLeJobSuivant();

    expect(AnnonceDeVersion::lue('worker'))->toBe([
        'version' => 'v1.5.20',
        'revision' => '8bb6b640acd728d1733b64de34f1aafc5897ae8f',
        'depuis' => '2026-10-02T14:07:00+02:00',
        'le' => '2026-10-02T14:07:00+02:00',
    ]);
});

/*
 * Un battement que la file `sync` exécute sur place tourne dans le processus
 * qui l'envoie, le scheduler : il annoncerait l'image du scheduler sous le nom
 * du worker, et masquerait un worker arrêté.
 */
it('ne prend pas pour une annonce du worker un battement traité sur place, sans file', function (): void {
    Config::set('queue.default', 'sync');

    Artisan::call('health:queue-check-heartbeat');

    expect(AnnonceDeVersion::lue('worker'))->toBeNull();
});

/*
 * Horizon traite tous les jobs de l'application : une annonce à chacun
 * coûterait une lecture et une écriture dans le cache par job. Seul le
 * battement de la file, chaque minute, vaut annonce du worker.
 */
it('ne fait pas réannoncer le worker par un autre job que le battement de la file', function (): void {
    versionsFileServieParUnWorker();
    dispatch(static function (): void {
    });

    versionsLeWorkerTraiteLeJobSuivant();

    // Le job a bien été traité, et sans erreur : sinon il serait resté dans la file.
    expect(DB::connection('file_des_versions')->table('jobs')->count())->toBe(0)
        ->and(AnnonceDeVersion::lue('worker'))->toBeNull();
});

/*
 * Octane garde ses travailleurs jusqu'à leur cinq-centième requête : app se
 * réannonce au sondage de santé de l'image, toutes les trente secondes, et
 * pas à chaque requête.
 */
it('fait réannoncer app à chaque sondage de /up, et à aucune autre requête', function (): void {
    $this->get('/login')->assertOk();

    expect(AnnonceDeVersion::lue('app'))->toBeNull();

    $this->get('/up')->assertOk();

    expect(AnnonceDeVersion::lue('app'))->toBeArray();
});

/*
 * Un écouteur qui lèverait pendant le sondage ferait répondre 500 à `/up`, et
 * le conteneur passerait pour malade.
 */
it('laisse /up répondre quand le cache est en panne', function (): void {
    Cache::shouldReceive('get')->atLeast()->once()->andThrow(new RuntimeException('Redis injoignable'));
    Cache::shouldReceive('forever')->andThrow(new RuntimeException('Redis injoignable'));

    $this->get('/up')->assertOk();
});

/*
 * Sous Sail, `artisan serve` ne passe pas par Octane : app ne s'annonçait
 * jamais, et le contrôle restait orange (#1930).
 */
it('ne juge pas les versions hors production, sans lire le cache', function (): void {
    versionsAnnonceePar('worker', 'dev', 'inconnue', '2026-10-02 13:00:00');
    $cache = Cache::spy();

    $resultat = VersionsDesConteneursCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->shortSummary)->toBe('Non jugé hors production')
        ->and($resultat->notificationMessage)->toContain('ne juge que la production');

    $cache->shouldNotHaveReceived('get');
});

it('met les versions au vert quand app, worker et scheduler exécutent la même image', function (): void {
    // Construit avant les annonces : le contrôle les lit à chaque passage.
    $controle = versionsControleEnProduction();

    foreach (AnnonceDeVersion::CONTENEURS as $conteneur) {
        versionsAnnonceePar($conteneur, 'v1.5.20', '8bb6b640acd728d1733b64de34f1aafc5897ae8f', '2026-10-02 13:00:00');
    }

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = $controle->run();

    expect($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
        ->and($resultat->shortSummary)->toBe('v1.5.20 (8bb6b64) partout');
});

it('met les versions au rouge quand un conteneur exécute une image en retard, et dit lequel et quoi faire', function (): void {
    versionsAnnonceePar('scheduler', 'v1.5.12', 'c6c53a9c00000000000000000000000000000000', '2026-09-06 22:15:00');
    versionsAnnonceePar('app', 'v1.5.20', '8bb6b640acd728d1733b64de34f1aafc5897ae8f', '2026-10-02 13:00:00');
    versionsAnnonceePar('worker', 'v1.5.20', '8bb6b640acd728d1733b64de34f1aafc5897ae8f', '2026-10-02 13:00:30');
    // Le scheduler s'annonce chaque minute, toujours avec sa vieille image.
    versionsAnnonceePar('scheduler', 'v1.5.12', 'c6c53a9c00000000000000000000000000000000', '2026-10-02 13:59:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->shortSummary)->toBe('app v1.5.20 (8bb6b64) · worker v1.5.20 (8bb6b64) · scheduler v1.5.12 (c6c53a9)')
        ->and($resultat->notificationMessage)
        ->toContain('scheduler exécute v1.5.12 (c6c53a9) depuis le 06/09/2026 à 22:15')
        ->toContain('dernière annonce le 02/10/2026 à 13:59')
        ->toContain('v1.5.20 (8bb6b64)')
        ->toContain('mettre à jour la pile en retéléchargeant l\'image')
        ->and($resultat->meta['en_retard'])->toBe(['scheduler']);
});

it('compare aussi la révision : deux images de main portent la même version', function (): void {
    versionsAnnonceePar('worker', 'main', '1111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '2026-10-01 09:00:00');
    versionsAnnonceePar('app', 'main', '2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '2026-10-02 09:00:00');
    versionsAnnonceePar('scheduler', 'main', '2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '2026-10-02 09:00:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->meta['en_retard'])->toBe(['worker']);
});

/*
 * `main` n'est pas une version publiée : comparée par version_compare, elle
 * passerait toujours sous v1.x, quelle que soit sa date. Entre une
 * construction de `main` et une version publiée, la date de la première
 * annonce départage.
 */
it('départage par la date une construction de main et une version publiée', function (): void {
    versionsAnnonceePar('worker', 'v1.5.20', 'bbbbbbb', '2026-10-01 09:00:00');
    versionsAnnonceePar('scheduler', 'v1.5.20', 'bbbbbbb', '2026-10-01 09:00:00');
    versionsAnnonceePar('app', 'main', 'ccccccc', '2026-10-02 13:00:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('failed', $resultat->notificationMessage)
        ->and($resultat->meta['en_retard'])->toBe(['worker', 'scheduler'])
        ->and($resultat->notificationMessage)->toContain('app main (ccccccc) depuis le 02/10/2026 à 13:00');
});

/*
 * Pendant une mise à jour de la pile, les conteneurs redémarrent l'un après
 * l'autre : un écart de quelques minutes n'est pas une panne, et un rouge
 * écrirait un courriel à chaque déploiement.
 */
it('laisse à l’orange un écart de moins de dix minutes, le temps que la pile se mette à jour', function (): void {
    versionsAnnonceePar('scheduler', 'v1.5.19', 'aaaaaaa', '2026-10-01 10:00:00');
    versionsAnnonceePar('worker', 'v1.5.19', 'aaaaaaa', '2026-10-01 10:00:00');
    versionsAnnonceePar('app', 'v1.5.20', 'bbbbbbb', '2026-10-02 13:55:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $pendant = versionsControleEnProduction()->run();

    expect($pendant->status->value)->toBe('warning')
        ->and($pendant->notificationMessage)->toContain('mise à jour en cours')
        ->and($pendant->meta['en_retard'])->toBe(['worker', 'scheduler']);

    Carbon::setTestNow('2026-10-02 14:06:00');

    expect(versionsControleEnProduction()->run()->status->value)->toBe('failed');
});

it('met les versions à l’orange quand un conteneur ne s’est jamais annoncé', function (): void {
    versionsAnnonceePar('app', 'v1.5.20', '8bb6b640', '2026-10-02 13:00:00');
    versionsAnnonceePar('worker', 'v1.5.20', '8bb6b640', '2026-10-02 13:00:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('app v1.5.20 (8bb6b64) · worker v1.5.20 (8bb6b64) · scheduler ?')
        ->and($resultat->notificationMessage)
        ->toContain('scheduler ne s\'est jamais annoncé')
        ->toContain('mettre à jour la pile en retéléchargeant l\'image')
        ->and($resultat->meta['absents'])->toBe(['scheduler']);
});

/*
 * Une annonce évincée revient datée de son retour : le worker resté sur une
 * ancienne image passerait pour le dernier mis à jour, et app et scheduler
 * pour les conteneurs en retard (#1930). Entre deux versions publiées, la plus
 * haute est la dernière, quelle que soit la date des annonces.
 */
it('garde au rouge le worker resté sur une ancienne version quand son annonce, évincée, revient au battement de la file', function (): void {
    versionsFileServieParUnWorker();
    versionsAnnonceePar('worker', 'v1.5.9', 'c6c53a9c00000000000000000000000000000000', '2026-09-06 22:15:00');
    versionsAnnonceePar('app', 'v1.5.20', '8bb6b640acd728d1733b64de34f1aafc5897ae8f', '2026-10-01 09:00:00');
    versionsAnnonceePar('scheduler', 'v1.5.20', '8bb6b640acd728d1733b64de34f1aafc5897ae8f', '2026-10-01 09:00:30');

    Cache::forget('sante:version:worker');
    Carbon::setTestNow('2026-10-02 13:59:00');
    Config::set('app.version', 'v1.5.9');
    Config::set('app.revision', 'c6c53a9c00000000000000000000000000000000');
    Artisan::call('health:queue-check-heartbeat');
    versionsLeWorkerTraiteLeJobSuivant();

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('failed', $resultat->notificationMessage)
        ->and($resultat->meta['en_retard'])->toBe(['worker'])
        ->and($resultat->notificationMessage)
        ->toContain('worker exécute v1.5.9 (c6c53a9) depuis le 02/10/2026 à 13:59')
        ->toContain('app et scheduler v1.5.20 (8bb6b64) depuis le 01/10/2026 à 09:00');
});

/*
 * La dernière image date de sa première annonce, tous conteneurs confondus :
 * un conteneur à jour dont l'annonce revient après une éviction ne fait pas
 * repasser à l'orange « mise à jour en cours » un écart vieux d'un jour.
 */
it('garde au rouge un écart ancien quand l’annonce d’un conteneur à jour revient après une éviction', function (): void {
    versionsAnnonceePar('worker', 'v1.5.19', 'aaaaaaa', '2026-09-06 22:15:00');
    versionsAnnonceePar('app', 'v1.5.20', 'bbbbbbb', '2026-10-01 09:00:00');
    versionsAnnonceePar('scheduler', 'v1.5.20', 'bbbbbbb', '2026-10-01 09:00:00');

    Cache::forget('sante:version:app');
    versionsAnnonceePar('app', 'v1.5.20', 'bbbbbbb', '2026-10-02 13:58:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = versionsControleEnProduction()->run();

    expect($resultat->status->value)->toBe('failed', $resultat->notificationMessage)
        ->and($resultat->meta['en_retard'])->toBe(['worker'])
        ->and($resultat->notificationMessage)->toContain('app et scheduler v1.5.20 (bbbbbbb) depuis le 01/10/2026 à 09:00');
});

/*
 * Arbitrage de #1930 : dans le cache, un retour en arrière vers une version
 * plus ancienne ressemble à l'annonce, revenue après une éviction, d'un
 * conteneur resté sur une ancienne image. La plus haute version publiée
 * reste donc la dernière image tant qu'un conteneur l'exécute encore :
 * pendant un retour en arrière de toute la pile, le contrôle passe au rouge
 * sans attendre les dix minutes, et désigne les conteneurs déjà revenus en
 * arrière. Il revient au vert quand le dernier a suivi.
 */
it('passe au rouge sans attendre dix minutes pendant un retour en arrière, tant qu’un conteneur exécute encore la plus haute version', function (): void {
    foreach (AnnonceDeVersion::CONTENEURS as $conteneur) {
        versionsAnnonceePar($conteneur, 'v1.5.20', 'bbbbbbb', '2026-10-01 09:00:00');
    }

    versionsAnnonceePar('app', 'v1.5.19', 'aaaaaaa', '2026-10-02 14:00:00');
    versionsAnnonceePar('scheduler', 'v1.5.19', 'aaaaaaa', '2026-10-02 14:00:30');

    Carbon::setTestNow('2026-10-02 14:01:00');
    $pendant = versionsControleEnProduction()->run();

    expect($pendant->status->value)->toBe('failed', $pendant->notificationMessage)
        ->and($pendant->meta['en_retard'])->toBe(['app', 'scheduler'])
        ->and($pendant->notificationMessage)
        ->toContain('app exécute v1.5.19 (aaaaaaa) depuis le 02/10/2026 à 14:00')
        ->toContain('worker v1.5.20 (bbbbbbb) depuis le 01/10/2026 à 09:00')
        ->toContain('mettre à jour la pile en retéléchargeant l\'image');

    versionsAnnonceePar('worker', 'v1.5.19', 'aaaaaaa', '2026-10-02 14:01:30');
    Carbon::setTestNow('2026-10-02 14:02:00');
    $apres = versionsControleEnProduction()->run();

    expect($apres->status->value)->toBe('ok', $apres->notificationMessage)
        ->and($apres->shortSummary)->toBe('v1.5.19 (aaaaaaa) partout');
});
