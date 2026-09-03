<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the templates index page', function (): void {
    $user = User::factory()->create();
    WorkoutTemplate::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('templates.index'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Workouts/Templates/Index')
            ->has('templates', 3)
        );
});

it('stores a new workout template', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $response = $this->actingAs($user)->post(route('templates.store'), [
        'name' => 'My New Template',
        'description' => 'Template Description',
        'exercises' => [
            [
                'id' => $exercise->id,
                'sets' => [
                    ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                ],
            ],
        ],
    ]);

    $response->assertRedirect(route('templates.index'));
    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My New Template',
        'user_id' => $user->id,
    ]);
});

it('fails to store template with invalid data (422)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('templates.store'), [
        'name' => '', // Required field
        'exercises' => [
            [
                'id' => 9999, // Invalid exercise ID
            ],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'exercises.0.id']);
});

it('executes a workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('templates.execute', $template));

    $response->assertRedirect();
    $workout = Workout::where('user_id', $user->id)->first();
    expect($workout)->not->toBeNull();
    $response->assertRedirect(route('workouts.show', $workout));
});

it('forbids executing another users template (404, indiscernable)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->post(route('templates.execute', $template));

    $response->assertNotFound();

    $this->assertDatabaseMissing('workouts', ['user_id' => $user2->id]);
});

it('saves a workout as a new template', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Session',
    ]);

    $response = $this->actingAs($user)->post(route('templates.save-from-workout', $workout));

    $response->assertRedirect(route('templates.index'));
    $response->assertSessionHas('success', 'Modèle enregistré avec succès !');

    $this->assertDatabaseHas('workout_templates', [
        'name' => 'My Session (Modèle)',
        'user_id' => $user->id,
    ]);
});

it('forbids saving another users workout as template (404, indiscernable)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->post(route('templates.save-from-workout', $workout));

    $response->assertNotFound();

    $this->assertDatabaseMissing('workout_templates', ['user_id' => $user2->id]);
});

it('deletes a workout template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->from(route('templates.index'))->delete(route('templates.destroy', $template));

    $response->assertRedirect(route('templates.index'));
    $this->assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

it('forbids deleting another users template (404, indiscernable)', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->delete(route('templates.destroy', $template));

    $response->assertNotFound();
    $this->assertDatabaseHas('workout_templates', ['id' => $template->id]);
});

it('redirects unauthenticated users', function (): void {
    $response = $this->get(route('templates.index'));
    $response->assertRedirect(route('login'));
});

it('renders the template creation page', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('templates.create'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Workouts/Templates/Create')
            ->has('exercises')
        );
});

/*
 * Editing a template used to 404 on both routes, and this file asserted the 404
 * as if it were the intended behaviour. It was not: the Edit page, the
 * UpdateWorkoutTemplateAction, its own tests, the WorkoutTemplateUpdateRequest
 * and the policy's update ability were all present and wired to the API
 * controller. Only the two web methods called abort(404), and nothing in the
 * interface linked to templates.edit — so the feature existed and was
 * unreachable.
 */
it('renders the edit page with the template lines and sets', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Développé couché']);
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Haut du corps']);
    $line = $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);
    $line->workoutTemplateSets()->create(['reps' => 8, 'weight' => 60, 'is_warmup' => false, 'order' => 0]);

    $this->actingAs($user)
        ->get(route('templates.edit', $template))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Templates/Edit')
            ->where('template.name', 'Haut du corps')
            ->where('template.workout_template_lines.0.exercise.name', 'Développé couché')
            ->where('template.workout_template_lines.0.workout_template_sets.0.reps', 8)
            ->has('exercises')
        );
});

it('updates a template and rebuilds its exercise lines', function (): void {
    $user = User::factory()->create();
    $kept = Exercise::factory()->create(['user_id' => $user->id]);
    $added = Exercise::factory()->create(['user_id' => $user->id]);
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Avant']);
    $stale = $template->workoutTemplateLines()->create(['exercise_id' => $kept->id, 'order' => 0]);
    $stale->workoutTemplateSets()->create(['reps' => 5, 'weight' => 40, 'is_warmup' => false, 'order' => 0]);

    $this->actingAs($user)
        ->put(route('templates.update', $template), [
            'name' => 'Après',
            'description' => 'Deux exercices',
            'exercises' => [
                ['id' => $added->id, 'sets' => [['reps' => 10, 'weight' => 70, 'is_warmup' => false]]],
                ['id' => $kept->id, 'sets' => [['reps' => 12, 'weight' => 30, 'is_warmup' => true]]],
            ],
        ])
        ->assertRedirect(route('templates.index'));

    $template->refresh()->load('workoutTemplateLines.workoutTemplateSets');

    $firstLine = $template->workoutTemplateLines->get(0);
    $secondLine = $template->workoutTemplateLines->get(1);
    \PHPUnit\Framework\Assert::assertNotNull($firstLine, 'the rebuilt template has no first line');
    \PHPUnit\Framework\Assert::assertNotNull($secondLine, 'the rebuilt template has no second line');

    $firstSet = $firstLine->workoutTemplateSets->get(0);
    \PHPUnit\Framework\Assert::assertNotNull($firstSet, 'the first line of the rebuilt template has no set');

    expect($template->name)->toBe('Après')
        ->and($template->description)->toBe('Deux exercices')
        ->and($template->workoutTemplateLines)->toHaveCount(2)
        ->and($firstLine->exercise_id)->toBe($added->id)
        ->and($secondLine->exercise_id)->toBe($kept->id)
        ->and($firstSet->reps)->toBe(10);

    // The rebuild must not leave the replaced line's sets behind.
    $this->assertDatabaseMissing('workout_template_lines', ['id' => $stale->id]);
    $this->assertDatabaseMissing('workout_template_sets', ['workout_template_line_id' => $stale->id]);
});

it('empties a template when the last exercise is removed', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id]);
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);

    $this->actingAs($user)
        ->put(route('templates.update', $template), ['name' => 'Vide', 'exercises' => []])
        ->assertRedirect(route('templates.index'));

    expect($template->refresh()->workoutTemplateLines)->toHaveCount(0);
});

it('rejects a template update with no name', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Intact']);

    $this->actingAs($user)
        ->put(route('templates.update', $template), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect($template->refresh()->name)->toBe('Intact');
});

it('will not let one user edit or update another user\'s template', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $owner->id, 'name' => 'Privé']);

    $this->actingAs($intruder)->get(route('templates.edit', $template))->assertNotFound();
    $this->actingAs($intruder)->put(route('templates.update', $template), ['name' => 'Volé'])->assertNotFound();

    expect($template->refresh()->name)->toBe('Privé');
});
