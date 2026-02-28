<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('authenticated user can view templates index', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Templates/Index')
            ->has('templates')
        );
});

test('authenticated user can view create template page', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('templates.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Templates/Create')
            ->has('exercises')
        );
});

test('user can create a workout template with exercises and sets', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->post(route('templates.store'), [
            'name' => 'Full Body Workout',
            'description' => 'A great workout',
            'exercises' => [
                [
                    'id' => $exercise->id,
                    'sets' => [
                        ['reps' => 10, 'weight' => 50, 'is_warmup' => false],
                        ['reps' => 8, 'weight' => 60, 'is_warmup' => false],
                    ],
                ],
            ],
        ])
        ->assertRedirect(route('templates.index'));

    assertDatabaseHas('workout_templates', [
        'user_id' => $user->id,
        'name' => 'Full Body Workout',
        'description' => 'A great workout',
    ]);

    // We need to fetch the template to check lines because IDs are auto-generated
    $template = WorkoutTemplate::where('name', 'Full Body Workout')->first();
    expect($template)->not->toBeNull();

    assertDatabaseHas('workout_template_lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 0,
    ]);

    // Check sets implicitly via count or specific values if possible
    $line = $template->workoutTemplateLines()->first();
    expect($line->workoutTemplateSets()->count())->toBe(2);
    expect($line->workoutTemplateSets()->first()->reps)->toBe(10);
});

test('store validation: name is required', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('templates.store'), [
            'description' => 'Missing name',
        ])
        ->assertSessionHasErrors('name');
});

test('store validation: exercises must have valid id', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('templates.store'), [
            'name' => 'Invalid Exercise',
            'exercises' => [
                ['id' => 99999], // Non-existent
            ],
        ])
        ->assertSessionHasErrors('exercises.0.id');
});

test('user cannot create template with another users private exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $privateExercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->post(route('templates.store'), [
            'name' => 'Stolen Exercise',
            'exercises' => [
                ['id' => $privateExercise->id],
            ],
        ])
        ->assertSessionHasErrors('exercises.0.id');
});

test('user can execute a template to start a workout', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id, 'name' => 'Exec Template']);
    $line = $template->workoutTemplateLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);
    $line->workoutTemplateSets()->create(['reps' => 5, 'weight' => 100, 'order' => 0, 'is_warmup' => false]);

    actingAs($user)
        ->post(route('templates.execute', $template))
        ->assertRedirect(); // Should redirect to workout show page

    assertDatabaseHas('workouts', [
        'user_id' => $user->id,
        'name' => 'Exec Template', // Usually uses template name
    ]);

    $workout = Workout::where('name', 'Exec Template')->first();
    assertDatabaseHas('workout_lines', ['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $workoutLine = $workout->workoutLines()->first();
    assertDatabaseHas('sets', ['workout_line_id' => $workoutLine->id, 'reps' => 5, 'weight' => 100]);
});

test('user can save an existing workout as a template', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    $workout = Workout::factory()->create(['user_id' => $user->id, 'name' => 'Good Workout']);
    $line = $workout->workoutLines()->create(['exercise_id' => $exercise->id, 'order' => 0]);
    $line->sets()->create(['reps' => 8, 'weight' => 80]);

    actingAs($user)
        ->post(route('templates.save-from-workout', $workout))
        ->assertRedirect(route('templates.index'));

    assertDatabaseHas('workout_templates', [
        'user_id' => $user->id,
        'name' => 'Good Workout (Modèle)',
    ]);
});

test('user can delete their own template', function (): void {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('templates.destroy', $template))
        ->assertRedirect();

    assertDatabaseMissing('workout_templates', ['id' => $template->id]);
});

test('authorization: cannot execute another users template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->post(route('templates.execute', $template))
        ->assertForbidden();
});

test('authorization: cannot save from another users workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->post(route('templates.save-from-workout', $workout))
        ->assertForbidden();
});

test('authorization: cannot delete another users template', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('templates.destroy', $template))
        ->assertForbidden();

    assertDatabaseHas('workout_templates', ['id' => $template->id]);
});

test('unauthenticated user cannot access templates', function (): void {
    get(route('templates.index'))->assertRedirect(route('login'));
    get(route('templates.create'))->assertRedirect(route('login'));
    post(route('templates.store'), [])->assertRedirect(route('login'));
});
