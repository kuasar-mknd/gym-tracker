<?php

declare(strict_types=1);

use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;

it('fetches exercise history correctly', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    // Workout 1 (Older)
    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(5),
    ]);
    $line1 = WorkoutLine::factory()->create([
        'workout_id' => $workout1->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line1->id,
        'weight' => 100,
        'reps' => 10,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line1->id,
        'weight' => 105,
        'reps' => 5,
    ]);

    // Workout 2 (Newer)
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
    ]);
    $line2 = WorkoutLine::factory()->create([
        'workout_id' => $workout2->id,
        'exercise_id' => $exercise->id,
    ]);
    Set::factory()->create([
        'workout_line_id' => $line2->id,
        'weight' => 110,
        'reps' => 8,
    ]);

    $action = app(FetchExerciseHistoryAction::class);
    $history = $action->execute($user, $exercise);

    expect($history)->toHaveCount(2);

    // Assert sorting (descending by started_at)
    expect($history[0]['workout_id'])->toBe($workout2->id);
    expect($history[1]['workout_id'])->toBe($workout1->id);

    // Assert Epley 1RM calculation: 100 * (1 + 10 / 30) = 133.33
    // 105 * (1 + 5 / 30) = 122.5
    // Max is 133.33 for workout 1
    // 110 * (1 + 8 / 30) = 139.33 for workout 2
    expect($history[0]['best_1rm'])->toBe(139.33);
    expect($history[1]['best_1rm'])->toBe(133.33);

    expect($history[0]['formatted_date'])->toBe($workout2->started_at->format('d/m'));
});

it('only includes workouts for the given user', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $exercise = Exercise::factory()->create();

    // User 1 Workout
    $workout1 = Workout::factory()->create([
        'user_id' => $user1->id,
        'started_at' => Carbon::now()->subDays(5),
    ]);
    WorkoutLine::factory()->create([
        'workout_id' => $workout1->id,
        'exercise_id' => $exercise->id,
    ]);

    // User 2 Workout
    $workout2 = Workout::factory()->create([
        'user_id' => $user2->id,
        'started_at' => Carbon::now()->subDays(2),
    ]);
    WorkoutLine::factory()->create([
        'workout_id' => $workout2->id,
        'exercise_id' => $exercise->id,
    ]);

    $action = app(FetchExerciseHistoryAction::class);
    $history = $action->execute($user1, $exercise);

    expect($history)->toHaveCount(1)
        ->and($history[0]['workout_id'])->toBe($workout1->id);
});

/*
 * Les deux tests ci-dessus executaient le corps du `map()` sans en verifier la
 * forme : onze des dix-neuf mutants de l'action survivaient. Concretement,
 * chacune de ces reecritures passait la suite au vert :
 *
 *  - retirer la cle `id`, `workout_name` ou `sets` de chaque entree rendue ;
 *  - retirer la cle `weight` ou `reps` de chaque serie ;
 *  - rendre le poids brut au lieu d'un flottant et les repetitions brutes au
 *    lieu d'un entier — invisible tant qu'aucune serie n'a de case vide ;
 *  - passer ces valeurs brutes a `calculate1RM()` ;
 *  - remplacer le zero de repli du meilleur 1RM par 1.0 ou -1.0, jamais
 *    execute tant qu'aucune ligne n'est depourvue de series.
 *
 * Les trois tests qui suivent ferment ces portes, en comparant a des valeurs
 * POSEES : la fabrique de `Set` tire un poids entre 0 et 200 et un nombre de
 * repetitions entre 1 et 20, donc toute attente calculee sur son tirage serait
 * une attente tiree au sort.
 *
 * `firstOrFail()` plutot que `[0]` : l'indice rend `array|null` a l'analyse
 * statique, et chaque acces a une cle ajouterait alors une entree au baseline
 * PHPStan — celui-ci ne doit que retrecir.
 */

it('rend une entree et une serie avec exactement leurs cles', function (): void {
    // Le 15 juin 2026 est un lundi, loin de tout changement d'heure : la date
    // formatee est ecrite en toutes lettres plus bas plutot que recalculee par
    // l'expression meme que ce test verifie.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Haut du corps',
        'started_at' => Carbon::parse('2026-06-15 09:00:00'),
    ]);

    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $historique = app(FetchExerciseHistoryAction::class)->execute($user, $exercise);
    $entree = $historique->firstOrFail();

    // La liste exacte, et dans cet ordre : c'est le contrat que la page
    // d'exercice consomme. `started_at`, ajoute pour le tri, doit avoir ete
    // retire ; aucune des cinq autres cles ne doit manquer.
    expect(array_keys($entree))->toBe([
        'id', 'workout_id', 'workout_name', 'formatted_date', 'best_1rm', 'sets',
    ]);

    expect($entree['id'])->toBe($line->id);
    expect($entree['workout_id'])->toBe($workout->id);
    expect($entree['workout_name'])->toBe('Haut du corps');
    expect($entree['formatted_date'])->toBe('15/06');
    expect($entree['best_1rm'])->toBe(133.33);

    // Une serie comparee d'un bloc : `toBe` sur un tableau exige les memes
    // cles, dans le meme ordre, avec les memes types. C'est ce qui tient a la
    // fois la forme (trois cles) et les conversions (100.0 et non 100, ou la
    // chaine que rend une colonne `decimal(8,2)`).
    expect($entree['sets'])->toHaveCount(1);
    expect($entree['sets']->firstOrFail())->toBe([
        'weight' => 100.0,
        'reps' => 10,
        'one_rep_max' => 133.33,
    ]);
});

it('rend zero pour une serie inscrite sans poids ni repetitions', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-15 09:00:00'),
    ]);

    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Les deux colonnes sont nullables et le restent : une serie ajoutee puis
    // laissee vide est le cas courant, pas un cas limite.
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => null,
        'reps' => null,
    ]);

    $historique = app(FetchExerciseHistoryAction::class)->execute($user, $exercise);
    $serie = $historique->firstOrFail()['sets']->firstOrFail();

    // C'est ici, et nulle part ailleurs, que les conversions se voient : sans
    // elles le contrat rend `null` la ou il annonce un nombre, et
    // `calculate1RM()` — qui accepte `float|int|string` sous `strict_types` —
    // refuse l'appel.
    expect($serie)->toBe([
        'weight' => 0.0,
        'reps' => 0,
        'one_rep_max' => 0.0,
    ]);
});

it('rend un meilleur 1RM de zero pour une ligne sans aucune serie', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-15 09:00:00'),
    ]);

    // Une ligne ajoutee a la seance et pas encore remplie : l'exercice figure
    // bien dans l'historique, avec un total a zero.
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $historique = app(FetchExerciseHistoryAction::class)->execute($user, $exercise);
    $entree = $historique->firstOrFail();

    expect($historique)->toHaveCount(1);
    expect($entree['id'])->toBe($line->id);
    expect($entree['sets'])->toHaveCount(0);

    // Le seul test qui execute le repli `?? 0.0`. Sans lui, ce zero pouvait
    // devenir n'importe quel nombre sans qu'aucune assertion ne bouge.
    expect($entree['best_1rm'])->toBe(0.0)->toBeFloat();
});
