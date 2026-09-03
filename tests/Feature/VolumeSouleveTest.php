<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Facades\DB;

/*
 * Le volume compte ce qui a ete souleve, pas ce qui etait prevu (#1499).
 *
 * Il s'affiche a cote de « Total seances » et se lit donc comme du travail
 * accompli. Une serie cochee est la seule preuve dont on dispose : les valeurs
 * seules ne distinguent pas une serie faite d'une serie proposee par un modele.
 */

/**
 * @return array{0: User, 1: WorkoutTemplate}
 */
function modeleDeQuatreSeries(): array
{
    $user = User::factory()->create(['total_volume' => 0]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $line = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
    ]);

    foreach (range(1, 4) as $ignored) {
        WorkoutTemplateSet::factory()->create([
            'workout_template_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
            'is_warmup' => false,
        ]);
    }

    return [$user, $template];
}

it('ne crédite rien quand on ouvre une séance depuis un modèle', function (): void {
    [$user, $template] = modeleDeQuatreSeries();

    $workout = app(CreateWorkoutFromTemplateAction::class)->execute($user, $template);

    // Quatre séries de 100 kg × 10 attendent l'utilisateur. Il n'a rien soulevé.
    expect((float) $workout->refresh()->workout_volume)->toBe(0.0)
        ->and((float) $user->refresh()->total_volume)->toBe(0.0);
});

it('crédite une série au moment où elle est validée, et pas avant', function (): void {
    $user = User::factory()->create(['total_volume' => 0]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $set = Set::factory()->naPasEteFaite()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    expect((float) $workout->refresh()->workout_volume)->toBe(0.0);

    $set->update(['is_completed' => true]);

    expect((float) $workout->refresh()->workout_volume)->toBe(1000.0)
        ->and((float) $user->refresh()->total_volume)->toBe(1000.0);
});

it('reprend le volume quand on dévalide une série', function (): void {
    $user = User::factory()->create(['total_volume' => 0]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $set = Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    expect((float) $workout->refresh()->workout_volume)->toBe(1000.0);

    // Se tromper de série et se décocher doit rendre le volume, sans quoi le
    // compteur ne redescend jamais — le défaut de la famille #1460.
    $set->update(['is_completed' => false]);

    expect((float) $workout->refresh()->workout_volume)->toBe(0.0)
        ->and((float) $user->refresh()->total_volume)->toBe(0.0);
});

/*
 * Le contrôle nocturne doit compter comme le modèle.
 *
 * `VerifyDataCoherence` compare les compteurs stockés à une somme qu'il
 * recalcule lui-même. Si les deux formules divergent, la commande signale des
 * écarts qui n'existent pas — et une alerte qui crie tous les jours pour rien
 * finit par n'être plus lue.
 */
it('ne signale aucun écart quand une série attend encore d’être faite', function (): void {
    $user = User::factory()->create(['total_volume' => 0]);
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create([
        'workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false,
    ]);
    Set::factory()->naPasEteFaite()->create([
        'workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false,
    ]);

    expect((float) $workout->refresh()->workout_volume)->toBe(1000.0);

    $this->artisan('app:verify-data-coherence')->assertExitCode(0);
});

/*
 * Les compteurs accumulés avant ce changement comptent du travail seulement
 * prévu. Sans chemin de réparation ils resteraient faux, et le contrôle
 * nocturne les signalerait chaque nuit sans recours — un contrôle qui ne peut
 * pas passer au vert finit désactivé.
 */
it('recale les compteurs hérités sur les séries réellement faites', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false]);
    Set::factory()->naPasEteFaite()->create([
        'workout_line_id' => $line->id, 'weight' => 100, 'reps' => 10, 'is_warmup' => false,
    ]);

    // Un compteur hérité de l'ancienne formule : il comptait les deux séries.
    DB::table('workouts')->where('id', $workout->id)->update(['workout_volume' => 2000]);
    DB::table('users')->where('id', $user->id)->update(['total_volume' => 2000]);

    $this->artisan('app:verify-data-coherence')->assertExitCode(1);

    $this->artisan('app:verify-data-coherence', ['--repair' => true])->assertExitCode(0);

    expect((float) $workout->refresh()->workout_volume)->toBe(1000.0)
        ->and((float) $user->refresh()->total_volume)->toBe(1000.0);
});
