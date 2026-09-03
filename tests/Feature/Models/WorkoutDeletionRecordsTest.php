<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;

/*
 * Supprimer une seance doit emporter les records qu'elle detenait.
 *
 * `recompute()` etait branche sur `Set::deleted` et `WorkoutLine::deleted`, mais
 * supprimer une seance efface lignes et series EN BASE — `ON DELETE CASCADE` —
 * sans qu'aucun de ces evenements ne parte. Et comme `personal_records.set_id`
 * et `.workout_id` sont en `ON DELETE SET NULL`, la ligne de record survivait,
 * simplement detachee.
 *
 * Le modele documentait deja ce piege exact pour le volume total, et le traitait.
 * Les records avaient ete oublies.
 */

/**
 * Un utilisateur, un exercice, et une seance portant une serie donnee.
 *
 * @return array{0: User, 1: Exercise, 2: Workout}
 */
function seanceAvecSerie(float $poids, int $repetitions = 5, ?User $user = null, ?Exercise $exercise = null): array
{
    $user ??= User::factory()->create();
    $exercise ??= Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);

    return [$user, $exercise, $workout];
}

/**
 * Le meilleur poids enregistre pour un utilisateur.
 */
function recordDePoids(User $user): ?float
{
    $value = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    return is_numeric($value) ? (float) $value : null;
}

it('efface le record quand la séance qui le portait est supprimée', function (): void {
    // 500 kg au lieu de 50 : la faute de frappe qui a motivé ce correctif.
    [$user, , $faute] = seanceAvecSerie(500);

    expect(recordDePoids($user))->toBe(500.0);

    $faute->delete();

    expect(recordDePoids($user))->toBeNull();
});

/**
 * Le defaut ne s'arretait pas a la ligne orpheline : il se perpetuait.
 *
 * Une fois le 500 kg fantome en place, la serie suivante a 100 kg voyait
 * 100 <= 500 et sortait sans rien ecrire, et le rafraichissement ne trouvait
 * aucun record pointant sur elle. Le chiffre restait donc faux pour toujours,
 * y compris dans les succes, dont le seuil de poids lit un `max('value')` sur
 * ces memes lignes.
 */
it('laisse une séance ultérieure reprendre le record', function (): void {
    [$user, $exercise, $faute] = seanceAvecSerie(500);

    $faute->delete();

    seanceAvecSerie(100, user: $user, exercise: $exercise);

    expect(recordDePoids($user))->toBe(100.0);
});

/**
 * Supprimer une seance ne doit pas emporter le record d'une autre.
 *
 * Le recalcul reconstruit a partir des series qui restent : celles d'une seance
 * conservee doivent continuer a compter.
 */
it('garde le record porté par une séance conservée', function (): void {
    [$user, $exercise] = seanceAvecSerie(120);

    [, , $legere] = seanceAvecSerie(80, user: $user, exercise: $exercise);

    expect(recordDePoids($user))->toBe(120.0);

    $legere->delete();

    expect(recordDePoids($user))->toBe(120.0);
});

/**
 * Deux exercices dans la meme seance : les deux doivent etre recalcules.
 *
 * Le releve des exercices se fait avant la cascade et dedoublonne ; un seul
 * exercice traite laisserait l'autre avec son record fantome.
 */
it('recalcule chaque exercice de la séance supprimée', function (): void {
    $user = User::factory()->create();
    $developpe = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $squat = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $workout = Workout::factory()->create(['user_id' => $user->id]);

    foreach ([$developpe, $squat] as $exercise) {
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 200,
            'reps' => 3,
            'is_warmup' => false,
        ]);
    }

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    $workout->delete();

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(0);
});
