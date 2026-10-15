<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\TexteNonFiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Les exceptions vues par le diagnostic (#2032) : groupées par type,
 * fichier et ligne, bornées à la fenêtre et à 5 000 lignes lues ; au
 * détail, sans arguments de pile, et le texte libre sous `TexteBrut`
 * seulement.
 */

/**
 * Une exception enregistrée, aux colonnes données par-dessus des valeurs
 * ordinaires.
 *
 * @param  array<string, mixed>  $colonnes
 */
function exceptionsDiagnosticEnregistrer(array $colonnes = []): ExceptionEnregistree
{
    return ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => 'boum',
        'file' => base_path('app/Models/User.php'),
        'line' => 12,
        'trace' => [],
        'method' => 'GET',
        'path' => 'seances/1',
        ...$colonnes,
    ]);
}

/**
 * La fenêtre des vingt-quatre heures qui précèdent l'horloge arrêtée.
 */
function exceptionsDiagnosticFenetre(): FenetreDeDiagnostic
{
    return FenetreDeDiagnostic::depuis(null, now());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
});

it('groupe par type, fichier et ligne, compte, date et nomme la route de la dernière occurrence', function (): void {
    Carbon::setTestNow('2026-10-12 08:00:00');
    $premiere = exceptionsDiagnosticEnregistrer(['route_context' => ['route name' => 'workouts.index']]);
    Carbon::setTestNow('2026-10-12 09:00:00');
    $derniere = exceptionsDiagnosticEnregistrer(['route_context' => ['route name' => 'workouts.show', 'controller' => 'X']]);
    $autreLigne = exceptionsDiagnosticEnregistrer(['line' => 13]);
    Carbon::setTestNow('2026-10-12 10:00:00');

    expect(new ProjectionDesExceptions()->liste(exceptionsDiagnosticFenetre()))->toBe([
        'groupes' => [
            [
                'type' => RuntimeException::class,
                'fichier' => 'app/Models/User.php',
                'ligne' => 12,
                'nombre' => 2,
                'premiere_le' => '2026-10-12T08:00:00+02:00',
                'derniere_le' => '2026-10-12T09:00:00+02:00',
                'dernier_id' => $derniere->id,
                'route' => 'workouts.show',
            ],
            [
                'type' => RuntimeException::class,
                'fichier' => 'app/Models/User.php',
                'ligne' => 13,
                'nombre' => 1,
                'premiere_le' => '2026-10-12T09:00:00+02:00',
                'derniere_le' => '2026-10-12T09:00:00+02:00',
                'dernier_id' => $autreLigne->id,
                'route' => null,
            ],
        ],
        'lignes_lues' => 3,
        'echantillon' => false,
        'tronque' => false,
    ])->and($premiere->id)->toBeLessThan($derniere->id);
});

it('range d’abord le groupe le plus nombreux, puis le plus récent à nombre égal', function (): void {
    $ancien = exceptionsDiagnosticEnregistrer(['line' => 1]);
    exceptionsDiagnosticEnregistrer(['line' => 2]);
    $recent = exceptionsDiagnosticEnregistrer(['line' => 3]);
    exceptionsDiagnosticEnregistrer(['line' => 2]);

    $groupes = new ProjectionDesExceptions()->liste(exceptionsDiagnosticFenetre())['groupes'];

    expect(array_column($groupes, 'ligne'))->toBe([2, 3, 1])
        ->and(array_column($groupes, 'dernier_id'))->toContain($ancien->id, $recent->id);
});

it('ne lit que la fenêtre demandée', function (): void {
    Carbon::setTestNow('2026-10-11 09:59:59');
    exceptionsDiagnosticEnregistrer(['line' => 1]);
    Carbon::setTestNow('2026-10-11 10:00:00');
    exceptionsDiagnosticEnregistrer(['line' => 2]);
    Carbon::setTestNow('2026-10-12 10:00:00');

    expect(array_column(new ProjectionDesExceptions()->liste(exceptionsDiagnosticFenetre())['groupes'], 'ligne'))->toBe([2]);
});

it('ne lit que les 5 000 dernières lignes, rend 50 groupes au plus, et le dit', function (): void {
    $lignes = array_map(static fn (int $rang): array => [
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => 'boum',
        'file' => base_path('app/Models/User.php'),
        'line' => $rang % 60,
        'trace' => '[]',
        'method' => 'GET',
        'path' => '/',
        'created_at' => now(),
        'updated_at' => now(),
    ], range(1, LecturesBornees::LIGNES_AU_PLUS + 1));

    foreach (array_chunk($lignes, 1_000) as $paquet) {
        DB::table('filament_exceptions_table')->insert($paquet);
    }

    $liste = new ProjectionDesExceptions()->liste(exceptionsDiagnosticFenetre());

    expect($liste['lignes_lues'])->toBe(LecturesBornees::LIGNES_AU_PLUS)
        ->and($liste['echantillon'])->toBeTrue()
        ->and($liste['tronque'])->toBeTrue()
        ->and($liste['groupes'])->toHaveCount(LecturesBornees::GROUPES_AU_PLUS)
        ->and(array_sum(array_column($liste['groupes'], 'nombre')))->toBeLessThanOrEqual(LecturesBornees::LIGNES_AU_PLUS);
});

it('rend le détail sans arguments de pile, et sans texte hors de TexteBrut', function (): void {
    $exception = exceptionsDiagnosticEnregistrer([
        'type' => \Illuminate\Database\QueryException::class,
        'code' => '23000',
        'method' => 'POST',
        'path' => 'seances/1?jeton=abc',
        'route_context' => ['route name' => 'workouts.update'],
        'trace' => [
            ['file' => base_path('app/Http/Controllers/WorkoutController.php'), 'line' => 40, 'class' => \App\Http\Controllers\WorkoutController::class, 'type' => '->', 'function' => 'update', 'args' => ['secret-de-pile']],
            ['file' => '/usr/lib/php/x.php', 'line' => 2, 'function' => '{closure:{closure:/srv/x.php:1}:2}'],
            ['function' => 'array_map'],
        ],
    ]);

    $detail = new ProjectionDesExceptions()->detail($exception->id, exceptionsDiagnosticFenetre(), false);

    expect($detail)->toBe([
        'id' => $exception->id,
        'type' => \Illuminate\Database\QueryException::class,
        'code' => '23000',
        'fichier' => 'app/Models/User.php',
        'ligne' => 12,
        'methode' => 'POST',
        'route' => 'workouts.update',
        'gabarit' => 'workouts/{workout}',
        'le' => '2026-10-12T10:00:00+02:00',
        'pile' => [
            ['fichier' => 'app/Http/Controllers/WorkoutController.php', 'ligne' => 40, 'appel' => 'App\Http\Controllers\WorkoutController->update'],
            ['fichier' => null, 'ligne' => 2, 'appel' => '{closure}'],
            ['fichier' => null, 'ligne' => null, 'appel' => 'array_map'],
        ],
    ]);
});

it('ajoute sous TexteBrut le message et le chemin, à expurger au budget de chacun', function (): void {
    $exception = exceptionsDiagnosticEnregistrer(['message' => 'boum pour jean@example.org', 'path' => 'seances/1']);

    $detail = new ProjectionDesExceptions()->detail($exception->id, exceptionsDiagnosticFenetre(), true);

    expect($detail['non_fiable'] ?? null)->toEqual([
        'message' => new TexteNonFiable('boum pour jean@example.org', ProjectionDesExceptions::BUDGET_DU_MESSAGE),
        'chemin' => new TexteNonFiable('seances/1', ProjectionDesExceptions::BUDGET_DU_CHEMIN),
    ]);
});

it('ne rend pas le détail d’une exception de plus de trente jours, ni d’une exception inconnue', function (): void {
    Carbon::setTestNow('2026-09-12 09:59:59');
    $ancienne = exceptionsDiagnosticEnregistrer();
    Carbon::setTestNow('2026-09-12 10:00:00');
    $limite = exceptionsDiagnosticEnregistrer();
    Carbon::setTestNow('2026-10-12 10:00:00');

    $projection = new ProjectionDesExceptions();

    expect($projection->detail($ancienne->id, exceptionsDiagnosticFenetre(), true))->toBeNull()
        ->and($projection->detail($limite->id, exceptionsDiagnosticFenetre(), false))->not->toBeNull()
        ->and($projection->detail($limite->id + 1, exceptionsDiagnosticFenetre(), false))->toBeNull();
});

it('rend une méthode inventée ou un code hors forme comme une valeur nulle', function (): void {
    $exception = exceptionsDiagnosticEnregistrer(['method' => 'PURGE', 'code' => 'x y', 'route_context' => ['route name' => 'inconnue.du.routeur']]);

    $detail = new ProjectionDesExceptions()->detail($exception->id, exceptionsDiagnosticFenetre(), false);

    expect(array_intersect_key($detail ?? [], array_flip(['methode', 'code', 'route', 'gabarit'])))
        ->toBe(['code' => null, 'methode' => null, 'route' => 'inconnue.du.routeur', 'gabarit' => null]);
});
