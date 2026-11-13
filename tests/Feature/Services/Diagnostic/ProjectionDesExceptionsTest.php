<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\LecturesBornees;
use App\Services\Diagnostic\Projections\ProjectionDesExceptions;
use App\Services\Diagnostic\TexteNonFiable;
use App\Services\Diagnostic\ValeurLue;
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

/*
 * La ligne est écrite sans les rappels du modèle, comme avant #2076, qui masque
 * désormais les adresses du message à l'écriture : une ligne de cet âge vit
 * trente jours, et la projection en rend le texte brut, à expurger.
 */
it('ajoute sous TexteBrut le message et le chemin, à expurger au budget de chacun', function (): void {
    $exception = ExceptionEnregistree::withoutEvents(static fn (): ExceptionEnregistree => exceptionsDiagnosticEnregistrer(['message' => 'boum pour jean@example.org', 'path' => 'seances/1']));

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

it('ne confond pas deux groupes qui ne diffèrent que par leur type, leur fichier ou leur ligne', function (): void {
    exceptionsDiagnosticEnregistrer();
    exceptionsDiagnosticEnregistrer(['type' => LogicException::class]);
    exceptionsDiagnosticEnregistrer(['file' => base_path('app/Models/Workout.php')]);
    exceptionsDiagnosticEnregistrer(['line' => 13]);

    $groupes = new ProjectionDesExceptions()->liste(exceptionsDiagnosticFenetre())['groupes'];

    expect($groupes)->toHaveCount(4)
        ->and(array_column($groupes, 'nombre'))->toBe([1, 1, 1, 1]);
});

/*
 * Une page compte 25 occurrences : le curseur n'existe qu'à la vingt-sixième,
 * et la lecture en demande une de plus que la page pour le savoir.
 */
it('ne donne de curseur suivant qu’au-delà de 25 occurrences', function (int $occurrences, ?int $rang): void {
    $identifiants = array_map(static fn (int $ligne): int => exceptionsDiagnosticEnregistrer()->id, range(1, $occurrences));
    $requetes = [];
    DB::listen(static function ($requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    $page = new ProjectionDesExceptions()->occurrences($identifiants[0], null, exceptionsDiagnosticFenetre());

    expect($page['suivant'] ?? null)->toBe($rang === null ? null : $identifiants[$occurrences - 1 - $rang])
        ->and(array_column($page['occurrences'] ?? [], 'id'))->toBe(array_slice(array_reverse($identifiants), 0, 25))
        ->and(array_values(array_filter($requetes, static fn (string $sql): bool => str_contains($sql, 'limit 26'))))->toHaveCount(1);
})->with([
    'vingt-cinq' => [25, null],
    'vingt-six' => [26, 24],
]);

/*
 * Le plancher est la 5 000e ligne la plus récente de la fenêtre : une
 * occurrence plus ancienne n'est pas lue, et la page le dit.
 */
it('ne remonte pas, pour les occurrences, au-delà de la 5 000e ligne la plus récente', function (): void {
    $ligne = [
        'type' => RuntimeException::class, 'code' => '0', 'message' => 'boum', 'file' => base_path('app/Models/User.php'), 'line' => 7,
        'trace' => '[]', 'method' => 'GET', 'path' => '/', 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('filament_exceptions_table')->insert(array_fill(0, 4, $ligne));
    $autres = array_fill(0, 4_998, [...$ligne, 'line' => 8]);

    foreach (array_chunk($autres, 1_000) as $lot) {
        DB::table('filament_exceptions_table')->insert($lot);
    }

    $identifiants = DB::table('filament_exceptions_table')->where('line', 7)->orderBy('id')->pluck('id')->all();
    $page = new ProjectionDesExceptions()->occurrences(ValeurLue::entier($identifiants[3]) ?? 0, null, exceptionsDiagnosticFenetre());

    expect(array_column($page['occurrences'] ?? [], 'id'))->toBe([$identifiants[3], $identifiants[2]])
        ->and($page['echantillon'] ?? null)->toBeTrue()
        ->and($page['suivant'] ?? null)->toBeNull();
});
