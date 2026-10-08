<?php

declare(strict_types=1);

use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;

function ligneDatee(User $user, Exercise $exercice, Carbon $quand): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => $quand]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    Set::factory()->create(['workout_line_id' => $workoutLine->id, 'weight' => 100, 'reps' => 5]);

    return $workoutLine;
}

/**
 * L'historique d'un exercice s'arrete a un an, comme sa courbe voisine.
 *
 * `ExerciseController::show()` borne `getExercise1RMProgress` a 365 jours et
 * appelait l'historique sans aucune fenetre — sur la meme page, dans la meme
 * methode. Tout partait dans la prop Inertia : trois ans d'un exercice
 * hebdomadaire font des centaines de lignes et des milliers de series.
 */
it('borne l’historique d’un exercice a un an', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $recente = ligneDatee($user, $exercice, now()->subDays(30));
    ligneDatee($user, $exercice, now()->subDays(400));

    $historique = app(FetchExerciseHistoryAction::class)->execute($user, $exercice);

    expect($historique)->toHaveCount(1)
        ->and($historique->first())->not->toBeNull()
        ->and($historique->first()['id'] ?? null)->toBe($recente->id);
});

it('garde une seance datee de la veille de la borne', function (): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    ligneDatee($user, $exercice, now()->subDays(364));

    expect(app(FetchExerciseHistoryAction::class)->execute($user, $exercice))->toHaveCount(1);
});

/*
 * La borne, à la seconde : la séance d'il y a un an jour pour jour entre, celle
 * d'une seconde plus tôt non. Le test qui précède ne la tenait pas : la date
 * de la séance et celle de la borne s'écrivent à la seconde, tombent presque
 * toujours dans la même, et une fenêtre de 364 jours gardait aussi sa séance.
 */
it('garde la séance d’il y a un an jour pour jour, et pas celle d’une seconde plus tôt', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $anniversaire = ligneDatee($user, $exercice, Carbon::parse('2025-06-15 12:00:00'));
    ligneDatee($user, $exercice, Carbon::parse('2025-06-15 11:59:59'));

    expect(app(FetchExerciseHistoryAction::class)->execute($user, $exercice)->pluck('id')->all())
        ->toBe([$anniversaire->id]);
});

/**
 * La garde du second jeune vit dans `StoreFastRequest::withValidator()`.
 *
 * Le controleur la refaisait juste apres, donc la meme requete non indexee
 * partait DEUX fois par POST — `Validator::passes()` execute ses fermetures
 * `after` sans condition, meme apres un echec. La branche d'erreur du
 * controleur etait morte ; sa requete ne l'etait pas.
 */
it('refuse un second jeune quand un autre est en cours', function (): void {
    $user = User::factory()->create();

    Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)
        ->post(route('tools.fasting.store'), ['start_time' => now()->toDateTimeString(), 'target_duration_minutes' => 960, 'type' => '16:8'])
        ->assertSessionHasErrors('base');

    expect(Fast::where('user_id', $user->id)->count())->toBe(1);
});

it('accepte un jeune quand aucun autre n’est en cours', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tools.fasting.store'), ['start_time' => now()->toDateTimeString(), 'target_duration_minutes' => 960, 'type' => '16:8'])
        ->assertSessionHasNoErrors();

    expect(Fast::where('user_id', $user->id)->where('status', 'active')->count())->toBe(1);
});
