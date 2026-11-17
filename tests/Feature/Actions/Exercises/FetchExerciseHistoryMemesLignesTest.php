<?php

declare(strict_types=1);

use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Traits\CalculatesOneRepMax;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * L'historique d'un exercice (#2351, point 1) lit les colonnes dénormalisées
 * de la ligne et trie en SQL, là où il joignait `workouts`, hydratait puis
 * triait en PHP. Ces tests rejouent l'ANCIENNE lecture, recopiée telle quelle
 * ci-dessous, sur les mêmes données, et exigent les mêmes lignes dans le même
 * ordre.
 */

/**
 * L'ancienne lecture : jointure sur `workouts`, tri en PHP.
 *
 * @return Collection<int, array<string, mixed>>
 */
function historiqueDAvantLaLectureDenormalisee(User $compte, Exercise $exercice): Collection
{
    $calcul = new class()
    {
        use CalculatesOneRepMax;

        public function un1rm(float $poids, int $repetitions): float
        {
            return $this->calculate1RM($poids, $repetitions);
        }
    };

    /** @phpstan-ignore-next-line */
    return WorkoutLine::query()
        ->select('workout_lines.*')
        ->join('workouts', 'workout_lines.workout_id', '=', 'workouts.id')
        ->where('workout_lines.exercise_id', $exercice->id)
        ->where('workouts.user_id', $compte->id)
        ->whereNotNull('workouts.started_at')
        ->where('workouts.started_at', '>=', now()->subDays(365))
        ->with(['workout', 'sets'])
        ->get()
        ->map(function (WorkoutLine $ligne) use ($calcul): array {
            $seance = $ligne->workout;

            $meilleur = $ligne->sets
                ->filter(fn (Set $serie): bool => $serie->is_completed && ! $serie->is_warmup
                    && $serie->weight !== null && $serie->weight > 0.0
                    && $serie->reps !== null && $serie->reps > 0)
                ->map(fn (Set $serie): float => $calcul->un1rm((float) $serie->weight, (int) $serie->reps))
                ->max();

            return [
                'id' => $ligne->id,
                'workout_id' => $seance->id,
                'workout_name' => $seance->name,
                'formatted_date' => $seance->started_at?->format('d/m'),
                'best_1rm' => $meilleur,
                'sets' => $ligne->sets->map(fn (Set $serie): array => [
                    'weight' => (float) $serie->weight,
                    'reps' => (int) $serie->reps,
                    'one_rep_max' => $calcul->un1rm((float) $serie->weight, (int) $serie->reps),
                    'is_completed' => $serie->is_completed,
                    'is_warmup' => $serie->is_warmup,
                ]),
                'started_at' => $seance->started_at,
            ];
        })
        ->sortByDesc('started_at')
        ->values()
        ->map(function (array $entree): array {
            unset($entree['started_at']);

            return $entree;
        });
}

/**
 * Une séance de l'exercice, commencée à l'instant donné, avec ses séries.
 *
 * @param  list<array<string, mixed>>  $series
 * @param  array<string, mixed>  $seance
 */
function ligneDHistoriqueLe(User $compte, Exercise $exercice, string $debut, array $series = [], array $seance = []): WorkoutLine
{
    $workout = Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => Carbon::parse($debut),
        ...$seance,
    ]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercice->id]);

    foreach ($series as $serie) {
        Set::factory()->create(['workout_line_id' => $ligne->id, ...$serie]);
    }

    return $ligne;
}

/**
 * Les requêtes que lance l'appel.
 *
 * @return list<string>
 */
function requetesDeLHistoriqueDe(callable $appel): array
{
    $requetes = [];
    DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });
    $appel();

    return $requetes;
}

it('rend les mêmes lignes dans le même ordre que la jointure sur workouts', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $compte = User::factory()->create();
    $autre = User::factory()->create();
    $exercice = Exercise::factory()->create();
    $unAutreExercice = Exercise::factory()->create();

    // Dans le désordre, pour que l'ordre rendu soit celui de la date et non de l'insertion.
    ligneDHistoriqueLe($compte, $exercice, '2026-05-01 18:00:00', [
        ['weight' => 100, 'reps' => 5],
        ['weight' => 60, 'reps' => 10, 'is_warmup' => true],
    ]);
    ligneDHistoriqueLe($compte, $exercice, '2026-06-10 07:30:00', [
        ['weight' => 110, 'reps' => 3],
        ['weight' => 140, 'reps' => 5, 'is_completed' => false],
    ]);
    ligneDHistoriqueLe($compte, $exercice, '2026-03-20 09:00:00', [['weight' => null, 'reps' => null]]);
    // Une séance en cours : commencée, sans fin.
    ligneDHistoriqueLe($compte, $exercice, '2026-06-15 11:00:00', [['weight' => 90, 'reps' => 8]], ['ended_at' => null]);
    // Une ligne sans série.
    ligneDHistoriqueLe($compte, $exercice, '2026-06-01 10:00:00');

    // Ce que la lecture doit écarter : un autre compte, un autre exercice, le futur n'en est pas.
    ligneDHistoriqueLe($autre, $exercice, '2026-06-12 10:00:00', [['weight' => 200, 'reps' => 1]]);
    ligneDHistoriqueLe($compte, $unAutreExercice, '2026-06-12 10:00:00', [['weight' => 200, 'reps' => 1]]);

    $apres = app(FetchExerciseHistoryAction::class)->execute($compte, $exercice);
    $avant = historiqueDAvantLaLectureDenormalisee($compte, $exercice);

    expect($apres)->toHaveCount(5)
        ->and($apres->all())->toEqual($avant->all())
        ->and($apres->pluck('id')->all())->toBe($avant->pluck('id')->all())
        ->and($apres->pluck('formatted_date')->all())->toBe(['15/06', '10/06', '01/06', '01/05', '20/03']);
});

it('tient la fenêtre de 365 jours à la seconde près, comme la jointure', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create();

    $pile = ligneDHistoriqueLe($compte, $exercice, '2025-06-15 12:00:00', [['weight' => 100, 'reps' => 5]]);
    ligneDHistoriqueLe($compte, $exercice, '2025-06-15 11:59:59', [['weight' => 100, 'reps' => 5]]);
    $juste = ligneDHistoriqueLe($compte, $exercice, '2025-06-15 12:00:01', [['weight' => 100, 'reps' => 5]]);

    $apres = app(FetchExerciseHistoryAction::class)->execute($compte, $exercice);
    $avant = historiqueDAvantLaLectureDenormalisee($compte, $exercice);

    expect($apres->pluck('id')->all())->toBe([$juste->id, $pile->id])
        ->and($apres->all())->toEqual($avant->all());
});

it('départage deux lignes de la même séance par la clef primaire croissante, comme la jointure', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => Carbon::parse('2026-06-10 10:00:00')]);
    $premiere = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercice->id]);
    $seconde = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercice->id]);

    $ids = app(FetchExerciseHistoryAction::class)->execute($compte, $exercice)->pluck('id')->all();

    expect($ids)->toBe([$premiere->id, $seconde->id])
        ->and(historiqueDAvantLaLectureDenormalisee($compte, $exercice)->pluck('id')->all())->toBe($ids);
});

it('lit l’historique en trois requêtes, sans jointure sur workouts ni tri en PHP', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create();
    foreach (range(1, 20) as $jour) {
        ligneDHistoriqueLe($compte, $exercice, Carbon::parse('2026-06-15 09:00:00')->subDays($jour)->toDateTimeString(), [
            ['weight' => 100, 'reps' => 5],
            ['weight' => 90, 'reps' => 8],
        ]);
    }

    $action = app(FetchExerciseHistoryAction::class);
    $apres = requetesDeLHistoriqueDe(fn (): Collection => $action->execute($compte, $exercice));
    $avant = requetesDeLHistoriqueDe(fn (): Collection => historiqueDAvantLaLectureDenormalisee($compte, $exercice));

    // Le nombre ne change pas (lignes, séances, séries) ; la forme de la première, si.
    expect($avant)->toHaveCount(3)
        ->and($avant[0])->toContain('inner join `workouts`')
        ->and($apres)->toHaveCount(3)
        ->and($apres[0])->not->toContain('join')
        ->and($apres[0])->toContain('where `user_id` = ? and `exercise_id` = ?')
        ->and($apres[0])->toContain('`workout_started_at` >= ?')
        ->and($apres[0])->toContain('order by `workout_started_at` desc, `id` asc');
});

it('ne lit pas l’historique dans la page, seulement dans la prop différée', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    ligneDHistoriqueLe($compte, $exercice, '2026-06-10 07:30:00', [['weight' => 110, 'reps' => 3]]);

    $page = requetesDeLHistoriqueDe(fn () => actingAs($compte)->get(route('exercises.show', $exercice))->assertOk());
    $differee = requetesDeLHistoriqueDe(fn () => actingAs($compte)
        ->get(route('exercises.show', $exercice), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Exercises/Show',
            'X-Inertia-Partial-Data' => 'history',
        ])->assertOk());

    $lecturesDeLignes = fn (array $requetes): array => array_values(array_filter(
        $requetes,
        static fn (mixed $sql): bool => is_string($sql) && str_contains($sql, 'order by `workout_started_at` desc, `id` asc'),
    ));

    expect($lecturesDeLignes($page))->toBe([])
        ->and($lecturesDeLignes($differee))->toHaveCount(1);
});
