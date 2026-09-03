<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/*
 * La progression calculee a la creation et a la modification doit etre ECRITE.
 *
 * `updateGoalProgress()` ne persiste rien : c'est `syncGoals()` qui ecrit, par un
 * upsert groupe. Le controleur appelait donc le calcul APRES avoir enregistre,
 * puis redirigeait — et la progression partait avec la reponse.
 *
 * Mesure avant correctif : un objectif « 100 kg » cree par quelqu'un qui souleve
 * deja 80 kg s'enregistrait a `current_value = 0` et `progress_pct = 0`. Le
 * chiffre se corrigeait tout seul plus tard, au premier enregistrement de seance,
 * ce qui rendait le defaut d'autant plus difficile a voir : il ne durait que
 * jusqu'a la prochaine seance.
 */

/**
 * Un utilisateur ayant deja souleve le poids indique sur un exercice.
 *
 * @return array{0: User, 1: Exercise}
 */
function utilisateurAyantSouleve(float $poids): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => 5,
        'is_warmup' => false,
    ]);

    return [$user, $exercise];
}

it('enregistre la progression déjà acquise à la création', function (): void {
    [$user, $exercise] = utilisateurAyantSouleve(80);

    $this->actingAs($user)
        ->post(route('goals.store'), [
            'title' => 'Développé 100 kg',
            'type' => 'weight',
            'target_value' => 100,
            'exercise_id' => $exercise->id,
            'deadline' => now()->addMonths(3)->toDateString(),
        ])
        ->assertRedirect();

    $goal = Goal::query()->where('user_id', $user->id)->firstOrFail();

    expect((float) $goal->current_value)->toBe(80.0)
        ->and((float) $goal->progress_pct)->toBe(80.0);
});

it('réécrit la progression quand la cible change', function (): void {
    [$user, $exercise] = utilisateurAyantSouleve(80);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => GoalType::Weight,
        'title' => 'Développé 100 kg',
        'start_value' => 0,
        'target_value' => 100,
        'current_value' => 80,
    ]);

    // La cible passe de 100 a 160 : a 80 kg souleves, on retombe a 50 %.
    $this->actingAs($user)
        ->put(route('goals.update', $goal), [
            'title' => 'Développé 160 kg',
            'type' => 'weight',
            'target_value' => 160,
            'exercise_id' => $exercise->id,
            'deadline' => now()->addMonths(6)->toDateString(),
        ])
        ->assertRedirect();

    $enBase = Goal::query()->findOrFail($goal->id);

    expect((float) $enBase->target_value)->toBe(160.0)
        ->and((float) $enBase->progress_pct)->toBe(50.0);
});
