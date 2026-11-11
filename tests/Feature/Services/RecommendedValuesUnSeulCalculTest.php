<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Les valeurs proposées n'ont qu'un calcul, qui remplit seul leur clef de cache
 * (#1513).
 *
 * La ligne seule (l'accesseur, que l'ajout d'un exercice par l'API demande) et
 * le lot (la page de la séance) avaient chacun leur requête, leur calcul et
 * leur écriture de la même clef, et ils avaient déjà répondu différemment sur
 * une séance saisie après coup. La ligne seule passe désormais par le calcul du
 * lot. La lecture de l'historique garde deux formes, que le nombre d'exercices
 * choisit : pour un seul, les cinq dernières lignes de l'index
 * `(user_id, exercise_id, workout_started_at)`, sans plus ; pour plusieurs,
 * une seule requête, qui classe les lignes de chacun par une fenêtre.
 */

/**
 * Une séance passée du compte, avec une ligne de l'exercice et ses séries, au
 * poids donné (une ligne sans série si le poids est nul).
 */
function unSeulCalculLigneDuPasse(User $compte, Exercise $exercice, string $debut, ?float $poids): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse($debut)]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    if ($poids !== null) {
        Set::factory()->count(2)->create(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => 8]);
    }

    return $ligne;
}

/**
 * La ligne d'une séance du compte qui commence à la date donnée.
 */
function unSeulCalculLigneDeLaSeance(User $compte, Exercise $exercice, string $debut): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse($debut)]);

    return WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
}

/**
 * Les requêtes qui lisent `workout_lines` pendant l'appel.
 *
 * @return list<string>
 */
function unSeulCalculLecturesDesLignes(callable $appel): array
{
    $lectures = [];

    DB::listen(static function (QueryExecuted $requete) use (&$lectures): void {
        if (str_contains($requete->sql, '`workout_lines`')) {
            $lectures[] = $requete->sql;
        }
    });

    $appel();

    return $lectures;
}

/**
 * Le lot pour une ligne, sa séance chargée.
 *
 * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
 */
function unSeulCalculLot(WorkoutLine ...$lignes): array
{
    $liste = array_values($lignes);
    $collection = new Collection($liste);
    $premiere = $liste[0];

    return app(RecommendedValuesService::class)->batchRecommendedValues($collection, $premiere->workout->user_id, $premiere->workout);
}

/*
 * Une séance saisie après coup, datée d'avant la dernière fois : ce qu'on y
 * propose vient des lignes d'avant SA date, par les deux entrées, et chacune
 * sert ensuite à l'autre ce qu'elle a mis en cache, sans relire l'historique.
 */
it('donne par la ligne seule et par le lot les mêmes valeurs sur une séance saisie après coup, et chacun sert le cache de l’autre', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);

    unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-01 18:00:00', 60.0);
    unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-10 18:00:00', 100.0);
    $saisieApresCoup = unSeulCalculLigneDeLaSeance($compte, $exercice, '2026-06-05 18:00:00');

    $service = app(RecommendedValuesService::class);

    Cache::flush();
    $parLaLigne = $service->getRecommendedValues($saisieApresCoup);
    $lotSurLeCacheDeLaLigne = unSeulCalculLecturesDesLignes(static fn (): array => unSeulCalculLot($saisieApresCoup));

    Cache::flush();
    $parLeLot = unSeulCalculLot($saisieApresCoup);
    $ligneSurLeCacheDuLot = unSeulCalculLecturesDesLignes(static fn (): array => $service->getRecommendedValues($saisieApresCoup));

    expect($parLaLigne['weight'])->toBe(60.0)
        ->and($parLeLot[$exercice->id] ?? null)->toBe($parLaLigne)
        ->and($lotSurLeCacheDeLaLigne)->toBe([])
        ->and($ligneSurLeCacheDuLot)->toBe([]);
});

it('ne lit pour la ligne seule que les cinq dernières lignes de son exercice, sans fenêtre', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-01 18:00:00', 60.0);
    $ligne = unSeulCalculLigneDeLaSeance($compte, $exercice, '2026-06-05 18:00:00');

    $lectures = unSeulCalculLecturesDesLignes(static fn (): array => app(RecommendedValuesService::class)->getRecommendedValues($ligne));

    expect($lectures)->toHaveCount(1)
        ->and($lectures[0])->toEndWith('order by `workout_started_at` desc, `id` desc limit 5')
        ->and(mb_strtolower($lectures[0]))->not->toContain('row_number');
});

/*
 * Le lot d'un seul exercice (une séance d'un exercice, ou un seul absent du
 * cache) lisait tout l'historique de l'exercice pour le classer par la
 * fenêtre, et n'en gardait que cinq lignes. Il lit désormais comme la ligne
 * seule.
 */
it('lit pour le lot d’un seul exercice comme pour la ligne seule', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-01 18:00:00', 60.0);
    $ligne = unSeulCalculLigneDeLaSeance($compte, $exercice, '2026-06-05 18:00:00');

    $lectures = unSeulCalculLecturesDesLignes(static fn (): array => unSeulCalculLot($ligne));

    expect($lectures)->toHaveCount(1)
        ->and($lectures[0])->toEndWith('order by `workout_started_at` desc, `id` desc limit 5')
        ->and(mb_strtolower($lectures[0]))->not->toContain('row_number');
});

it('lit pour le lot de plusieurs exercices l’historique de tous en une requête, classée par une fenêtre', function (): void {
    $compte = User::factory()->create();
    $developpe = Exercise::factory()->create(['user_id' => $compte->id]);
    $traction = Exercise::factory()->create(['user_id' => $compte->id]);
    unSeulCalculLigneDuPasse($compte, $developpe, '2026-06-01 18:00:00', 60.0);
    unSeulCalculLigneDuPasse($compte, $traction, '2026-06-01 18:00:00', 10.0);

    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse('2026-06-05 18:00:00')]);
    $lignes = [
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $developpe->id]),
        WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $traction->id]),
    ];

    $valeurs = [];
    $lectures = unSeulCalculLecturesDesLignes(static function () use ($lignes, &$valeurs): void {
        $valeurs = unSeulCalculLot(...$lignes);
    });

    expect($lectures)->toHaveCount(1)
        ->and(mb_strtolower($lectures[0]))->toContain('row_number() over (partition by exercise_id')
        ->and($lectures[0])->toEndWith('order by `workout_started_at` desc, `id` desc')
        ->and($valeurs[$developpe->id]['weight'] ?? null)->toBe(60.0)
        ->and($valeurs[$traction->id]['weight'] ?? null)->toBe(10.0);
});

/*
 * Les deux formes remontent l'historique jusqu'à la cinquième ligne qui
 * précède la séance, pas au-delà : sous quatre lignes restées vides, la
 * cinquième propose ; sous cinq, la sixième ne propose plus rien.
 */
it('remonte jusqu’à la cinquième ligne et pas au-delà, pour un exercice comme pour plusieurs', function (int $exercices): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse('2026-06-20 18:00:00')]);
    $attendus = [];
    $lignes = [];

    foreach (range(1, $exercices) as $rang) {
        $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
        $vides = $rang === 1 ? 4 : 5;

        unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-01 18:00:00', 40.0 + $rang);

        foreach (range(1, $vides) as $jour) {
            unSeulCalculLigneDuPasse($compte, $exercice, sprintf('2026-06-%02d 18:00:00', 1 + $jour), null);
        }

        $attendus[$exercice->id] = $vides === 4 ? 40.0 + $rang : 0.0;
        $lignes[] = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    }

    $valeurs = unSeulCalculLot(...$lignes);

    expect(array_map(static fn (array $proposees): float => $proposees['weight'], $valeurs))->toBe($attendus);
})->with([
    'un exercice' => [1],
    'plusieurs' => [2],
]);

/*
 * La dernière fois est la ligne la plus récente d'avant la séance, à sa date :
 * ni la plus ancienne, ni la dernière écrite, ni celle d'une autre séance
 * commencée au même instant, qui n'est pas d'avant. À date égale, l'ordre écrit dans
 * la requête départage par l'identifiant (les deux formes l'écrivent, plus
 * haut) : le parcours à rebours de l'index rend déjà l'identifiant
 * décroissant, et seules les données ne le prouveraient pas
 * (`.ai/rules/services.md`).
 */
it('propose d’après la ligne la plus récente d’avant la séance, pour un exercice comme pour plusieurs', function (int $exercices): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse('2026-06-20 18:00:00')]);
    $attendus = [];
    $lignes = [];

    foreach (range(1, $exercices) as $rang) {
        $exercice = Exercise::factory()->create(['user_id' => $compte->id]);

        unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-10 18:00:00', 80.0 + $rang);
        unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-01 18:00:00', 60.0 + $rang);
        unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-25 18:00:00', 120.0 + $rang);
        unSeulCalculLigneDuPasse($compte, $exercice, '2026-06-20 18:00:00', 150.0 + $rang);

        $attendus[$exercice->id] = 80.0 + $rang;
        $lignes[] = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    }

    $valeurs = unSeulCalculLot(...$lignes);

    expect(array_map(static fn (array $proposees): float => $proposees['weight'], $valeurs))->toBe($attendus);
})->with([
    'un exercice' => [1],
    'plusieurs' => [2],
]);
