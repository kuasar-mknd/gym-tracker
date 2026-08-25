<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
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
                ->has('measurementTypes', 2)
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
