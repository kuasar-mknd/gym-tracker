<?php

declare(strict_types=1);

use App\Support\Sante\AnnonceDeVersion;
use App\Support\Sante\VersionsDesConteneursCheck;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\WorkerStarting as DemarrageDUnTravailleurDeFile;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\WorkerStarting as DemarrageDUnTravailleurOctane;

/*
 * Le scheduler de production a tourné des semaines sur une vieille image sans que
 * rien ne le dise (#1813) : un conteneur garde l'image avec laquelle il a été
 * créé, et retélécharger `:v1` ne le met pas à jour. Chaque conteneur dit
 * désormais au démarrage, dans le cache, quelle image il exécute, et la page
 * « Santé » compare.
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

it('met les versions au vert quand app, worker et scheduler exécutent la même image', function (): void {
    // Construit avant les annonces : le contrôle les lit à chaque passage.
    $controle = VersionsDesConteneursCheck::new();

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
    $resultat = VersionsDesConteneursCheck::new()->run();

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
    $resultat = VersionsDesConteneursCheck::new()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->meta['en_retard'])->toBe(['worker']);
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
    $pendant = VersionsDesConteneursCheck::new()->run();

    expect($pendant->status->value)->toBe('warning')
        ->and($pendant->notificationMessage)->toContain('mise à jour en cours')
        ->and($pendant->meta['en_retard'])->toBe(['worker', 'scheduler']);

    Carbon::setTestNow('2026-10-02 14:06:00');

    expect(VersionsDesConteneursCheck::new()->run()->status->value)->toBe('failed');
});

it('met les versions à l’orange quand un conteneur ne s’est jamais annoncé', function (): void {
    versionsAnnonceePar('app', 'v1.5.20', '8bb6b640', '2026-10-02 13:00:00');
    versionsAnnonceePar('worker', 'v1.5.20', '8bb6b640', '2026-10-02 13:00:00');

    Carbon::setTestNow('2026-10-02 14:00:00');
    $resultat = VersionsDesConteneursCheck::new()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('app v1.5.20 (8bb6b64) · worker v1.5.20 (8bb6b64) · scheduler ?')
        ->and($resultat->notificationMessage)
        ->toContain('scheduler ne s\'est jamais annoncé')
        ->toContain('mettre à jour la pile en retéléchargeant l\'image')
        ->and($resultat->meta['absents'])->toBe(['scheduler']);
});
