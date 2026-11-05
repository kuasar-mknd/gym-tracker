<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * A goal could be created and then never touched again. The update and destroy
 * routes existed and worked, the policy allowed it, but no page reached them —
 * and the edit route pointed at a controller method that did not exist.
 */
it('renders the edit page filled with the goal it was asked for', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => null]);
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'title' => 'Squat 100kg',
        'type' => 'weight',
        'target_value' => 100,
        'exercise_id' => $exercise->id,
        'deadline' => now()->addMonth(),
    ]);

    actingAs($user)
        ->get(route('goals.edit', ['goal' => $goal->id]))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Goals/Edit')
                ->where('goal.title', 'Squat 100kg')
                ->where('goal.type', 'weight')
                ->where('goal.target_value', 100)
                ->where('goal.exercise_id', $exercise->id)
                ->has('exercises')
                // Deux, et non cinq : trois mensurations etaient proposees sans
                // exister comme colonnes de body_measurements, et provoquaient
                // une erreur 500 des que la progression etait calculee. Ce test
                // figeait l'etat casse (#1454).
                ->has('measurementTypes', count(\App\Http\Controllers\GoalController::measurementTypeValues()))
        );
});

/**
 * The deadline is a date cast, so it serialises as a full ISO timestamp. An
 * <input type="date"> renders blank for anything that is not Y-m-d — the goal
 * would have looked like it never had a deadline, and saving would have cleared
 * the one it did have.
 */
it('hands the deadline to the form in the only format a date input accepts', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'frequency',
        'exercise_id' => null,
        'deadline' => '2026-12-24',
    ]);

    actingAs($user)
        ->get(route('goals.edit', ['goal' => $goal->id]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('goal.deadline', '2026-12-24'));
});

it('refuses to open someone else edit form', function (): void {
    // Pose, non tire au sort : voir ExerciseControllerTest, meme piege.
    $goal = Goal::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Objectif souleve de terre 180 kg',
    ]);

    actingAs(User::factory()->create())
        ->get(route('goals.edit', ['goal' => $goal->id]))
        ->assertNotFound()
        ->assertDontSee($goal->title);
});

/**
 * The create rules require a deadline after today, which is right when the goal
 * is being set. Applied unchanged to an update they made a missed goal
 * impossible to edit: the form resent the stored date and validation refused
 * it. Correcting a goal you missed is exactly when you reach for the edit form.
 */
it('lets a goal whose deadline has passed still be edited', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'title' => 'Objectif manqué',
        'type' => 'frequency',
        'exercise_id' => null,
        'deadline' => now()->subMonth()->format('Y-m-d'),
    ]);

    actingAs($user)
        ->put(route('goals.update', ['goal' => $goal->id]), [
            'title' => 'Objectif repoussé',
            'type' => 'frequency',
            'target_value' => 40,
            'deadline' => $goal->deadline->format('Y-m-d'),
        ])
        ->assertSessionHasNoErrors();

    expect($goal->refresh()->title)->toBe('Objectif repoussé');
});

/**
 * The relaxation above is scoped to a deadline the user is not touching. Moving
 * one into the past is still a mistake and still refused.
 */
it('still refuses a deadline moved into the past', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'frequency',
        'exercise_id' => null,
        'deadline' => now()->addMonth()->format('Y-m-d'),
    ]);

    actingAs($user)
        ->put(route('goals.update', ['goal' => $goal->id]), [
            'title' => 'Hier',
            'type' => 'frequency',
            'target_value' => 40,
            'deadline' => now()->subDay()->format('Y-m-d'),
        ])
        ->assertSessionHasErrors(['deadline']);
});

/**
 * Un objectif de départ 50, de cible 100 et au titre « Avant », pour le cas
 * demandé, et les champs que son formulaire envoie selon son type.
 *
 * @return array{0: Goal, 1: array<string, mixed>, 2: float} l'objectif, les champs propres à son type, la valeur actuelle attendue une fois le départ ramené à 0
 */
function objectifAuDepartQuiSeVide(string $cas, User $user): array
{
    $exercice = Exercise::factory()->create(['user_id' => null]);

    [$type, $propres, $attendue] = match ($cas) {
        'charge, exercice sans record' => ['weight', ['exercise_id' => $exercice->id], 0.0],
        'charge, exercice avec un record de 70' => ['weight', ['exercise_id' => $exercice->id], 70.0],
        'volume, exercice sans séance' => ['volume', ['exercise_id' => $exercice->id], 0.0],
        'fréquence' => ['frequency', [], 0.0],
        'poids de corps, sans pesée' => ['measurement', ['measurement_type' => 'weight'], 0.0],
        default => throw new LogicException("Cas inconnu : {$cas}"),
    };

    if ($attendue > 0) {
        PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'type' => 'max_weight',
            'value' => $attendue,
        ]);
    }

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'title' => 'Avant',
        'type' => $type,
        'start_value' => 50,
        'target_value' => 100,
        'current_value' => 50,
        'exercise_id' => $propres['exercise_id'] ?? null,
        'measurement_type' => $propres['measurement_type'] ?? null,
    ]);

    return [$goal, $propres, $attendue];
}

dataset('objectifs dont le départ se vide à la modification', [
    'charge, exercice sans record',
    'charge, exercice avec un record de 70',
    'volume, exercice sans séance',
    'fréquence',
    'poids de corps, sans pesée',
]);

/**
 * Un départ vidé à la modification vaut 0, comme à la création (#2165).
 *
 * Le formulaire de l'application présente le départ comme facultatif, et le
 * même champ vide vaut 0 à la création. La modification le transmettait nul
 * à une colonne `NOT NULL`, directement ou par la valeur actuelle que le
 * recalcul en tire faute de record ou de pesée : la réponse était une 500, et
 * rien n'était enregistré, ni le départ ni le titre et la cible envoyés avec
 * lui. Un champ vidé arrive nul (`ConvertEmptyStringsToNull`), un champ blanc
 * aussi, après `TrimStrings`. La progression se recalcule depuis 0 : avec un
 * record de 70 et une cible de 120, 70 / 120.
 */
it('ramène à 0 un départ vidé à la modification, et enregistre le reste de l’envoi', function (string $cas, ?string $depart): void {
    $user = User::factory()->create();
    [$goal, $propres, $attendue] = objectifAuDepartQuiSeVide($cas, $user);

    actingAs($user)
        ->put(route('goals.update', ['goal' => $goal->id]), [
            'title' => 'Après',
            'type' => $goal->type->value,
            'target_value' => 120,
            'start_value' => $depart,
            ...$propres,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('goals.index'));

    $goal->refresh();

    expect($goal->title)->toBe('Après')
        ->and($goal->target_value)->toBe(120.0)
        ->and($goal->start_value)->toBe(0.0)
        ->and($goal->current_value)->toBe($attendue)
        ->and((float) $goal->progress_pct)->toEqualWithDelta($attendue / 120 * 100, 0.0001);
})->with('objectifs dont le départ se vide à la modification')->with([
    'départ nul' => [null],
    'départ vide' => [''],
    'départ fait d’espaces' => ['   '],
]);

/**
 * La contre-épreuve : un départ absent du corps ne change pas. La requête ne
 * ramène à 0 qu'un départ envoyé vide, et `update()` ne remplit que ce
 * qu'elle a validé.
 */
it('garde le départ en base quand la modification ne l’envoie pas', function (string $cas): void {
    $user = User::factory()->create();
    [$goal, $propres] = objectifAuDepartQuiSeVide($cas, $user);

    actingAs($user)
        ->put(route('goals.update', ['goal' => $goal->id]), [
            'title' => 'Après',
            'type' => $goal->type->value,
            'target_value' => 120,
            ...$propres,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('goals.index'));

    $goal->refresh();

    expect($goal->title)->toBe('Après')
        ->and($goal->target_value)->toBe(120.0)
        ->and($goal->start_value)->toBe(50.0);
})->with('objectifs dont le départ se vide à la modification');

it('deletes a goal from the page that now offers it', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('goals.destroy', ['goal' => $goal->id]))
        ->assertRedirect(route('goals.index'));

    expect(Goal::find($goal->id))->toBeNull();
});

/**
 * Route::resource generated a goals.create route pointing at a controller
 * method that was never written, so following it produced a server error.
 * Goals are created from a form that expands on the index; there is no create
 * page and the route should not exist.
 */
it('does not advertise a create page that was never built', function (): void {
    expect(Route::has('goals.create'))->toBeFalse();
});
