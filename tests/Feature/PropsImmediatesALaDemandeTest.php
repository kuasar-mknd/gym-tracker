<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Une réponse partielle ne calcule que les props qu'elle demande (#2148,
 * étape 3a, seconde moitié).
 *
 * Inertia ne saute, dans une réponse partielle, que les props encore à
 * calculer : un contrôleur qui étale des valeurs déjà calculées les relit
 * toutes, puis la réponse les jette. La requête de la prop différée des
 * statistiques relisait ainsi la pesée et la bibliothèque d'exercices, celle
 * des habitudes la grille de la semaine, celle des mensurations les cent
 * dernières pesées, et le rechargement de la séance après le vidage de la file
 * (`router.reload({ only: ['workout'] })`) la bibliothèque d'exercices. Ces
 * props sont désormais des fermetures.
 *
 * Chaque cas compte les instructions SQL d'une visite d'Inertia, le cache vidé
 * avant (une lecture en cache n'atteindrait pas la base) : la page entière
 * lit chaque prop immédiate, c'est le témoin qui dit que les fragments
 * cherchés sont les bons ; la réponse partielle n'en lit aucune.
 */

/**
 * Un compte qui a de quoi remplir chacune des quatre pages : des pesées, une
 * habitude cochée cette semaine, une séance avec une ligne et ses séries.
 */
function propsImmediatesSemerUnCompte(User $compte): Workout
{
    BodyMeasurement::factory()->count(3)->sequence(
        ['measured_at' => now()->subDays(1)],
        ['measured_at' => now()->subDays(8)],
        ['measured_at' => now()->subDays(15)],
    )->create(['user_id' => $compte->id]);

    $habitude = Habit::factory()->create(['user_id' => $compte->id, 'archived' => false]);
    HabitLog::factory()->create(['habit_id' => $habitude->id, 'user_id' => $compte->id, 'date' => now()->toDateString()]);

    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour(), 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'user_id' => $compte->id,
        'exercise_id' => Exercise::factory()->create(['user_id' => null])->id,
        'workout_started_at' => $seance->started_at,
    ]);
    Set::factory()->count(2)->create(['workout_line_id' => $ligne->id]);

    return $seance;
}

/**
 * Les instructions SQL d'une visite d'Inertia, le cache vidé juste avant.
 *
 * @param  array<string, string>  $enPlus  les en-têtes d'une réponse partielle
 * @return array{reponse: TestResponse<Response>, instructions: list<string>}
 */
function propsImmediatesVisiter(User $compte, string $adresse, array $enPlus = []): array
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        // Les en-têtes passent à la seule requête (`.ai/rules/tests.md`).
        $reponse = actingAs($compte)->get($adresse, [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            ...$enPlus,
        ]);
    } finally {
        DB::disableQueryLog();
    }

    return [
        'reponse' => $reponse->assertOk(),
        'instructions' => array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog())),
    ];
}

/**
 * Les instructions qui contiennent l'un des fragments.
 *
 * @param  list<string>  $instructions
 * @param  list<string>  $fragments
 * @return list<string>
 */
function propsImmediatesLues(array $instructions, array $fragments): array
{
    return array_values(array_filter(
        $instructions,
        static fn (string $instruction): bool => array_any($fragments, static fn (string $fragment): bool => str_contains($instruction, $fragment)),
    ));
}

/*
 * La bibliothèque d'exercices, telle que `Exercise::enCachePourUtilisateur()`
 * la lit.
 */
const PROPS_IMMEDIATES_BIBLIOTHEQUE = 'from `exercises` where (`user_id` is null or `user_id` = ?) order by `category` asc, `name` asc';

/*
 * La page, sa prop différée (ou la prop qu'un rechargement demande), et les
 * fragments des instructions que lisent ses props immédiates.
 */
dataset('pages aux props immédiates', [
    'Statistiques : la pesée et la bibliothèque' => [
        fn (User $compte, Workout $seance): string => route('stats.index'),
        'Stats/Index',
        'deferredData',
        ['`body_measurements`.`user_id` is not null order by `measured_at` desc limit 2', PROPS_IMMEDIATES_BIBLIOTHEQUE],
    ],
    'Habitudes : les habitudes et leurs suivis de la semaine' => [
        fn (User $compte, Workout $seance): string => route('habits.index'),
        'Habits/Index',
        'stats',
        ['from `habits`', 'from `habit_logs` where `habit_logs`.`habit_id` in'],
    ],
    'Mensurations : les cent dernières pesées' => [
        fn (User $compte, Workout $seance): string => route('body-measurements.index'),
        'Measurements/Index',
        'bodyStats',
        ['order by `measured_at` desc limit 100'],
    ],
    'Séance : la bibliothèque, au rechargement de la seule séance' => [
        fn (User $compte, Workout $seance): string => route('workouts.show', $seance),
        'Workouts/Show',
        'workout',
        [PROPS_IMMEDIATES_BIBLIOTHEQUE],
    ],
]);

it('lit chaque prop immédiate pour la page entière (témoin)', function (Closure $adresse, string $composant, string $demandee, array $fragments): void {
    $compte = User::factory()->create();
    $seance = propsImmediatesSemerUnCompte($compte);
    /** @var list<string> $fragments */
    /** @var string $chemin */
    $chemin = $adresse($compte, $seance);
    ['reponse' => $reponse, 'instructions' => $instructions] = propsImmediatesVisiter($compte, $chemin);

    $reponse->assertJsonPath('component', $composant);

    foreach ($fragments as $fragment) {
        expect(propsImmediatesLues($instructions, [$fragment]))->not->toBeEmpty("{$fragment}\n".implode("\n", $instructions));
    }
})->with('pages aux props immédiates');

it('ne lit aucune prop immédiate pour une réponse partielle qui ne la demande pas', function (Closure $adresse, string $composant, string $demandee, array $fragments): void {
    $compte = User::factory()->create();
    $seance = propsImmediatesSemerUnCompte($compte);
    /** @var list<string> $fragments */
    /** @var string $chemin */
    $chemin = $adresse($compte, $seance);
    ['reponse' => $reponse, 'instructions' => $instructions] = propsImmediatesVisiter($compte, $chemin, [
        'X-Inertia-Partial-Component' => $composant,
        'X-Inertia-Partial-Data' => $demandee,
    ]);

    $props = $reponse->json('props');
    expect($props)->toBeArray()->toHaveKey($demandee)
        ->and(propsImmediatesLues($instructions, $fragments))->toBe([], implode("\n", $instructions));
})->with('pages aux props immédiates');
