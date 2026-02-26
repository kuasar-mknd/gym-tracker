<?php

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

test('index returns user workout template lines', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    $line = WorkoutTemplateLine::create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 0
    ]);

    // Other user's data
    $otherTemplate = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    WorkoutTemplateLine::create([
        'workout_template_id' => $otherTemplate->id,
        'exercise_id' => $exercise->id,
        'order' => 0
    ]);

    actingAs($user)
        ->getJson(route('api.v1.workout-template-lines.index', ['filter[workout_template_id]' => $template->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonFragment(['id' => $line->id]);
});

test('store creates workout template line', function () {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();

    $data = [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 5,
    ];

    actingAs($user)
        ->postJson(route('api.v1.workout-template-lines.store'), $data)
        ->assertCreated()
        ->assertJsonFragment(['order' => 5]);

    expect($template->workoutTemplateLines()->count())->toBe(1);
});

test('store validates workout template ownership', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.workout-template-lines.store'), [
            'workout_template_id' => $template->id,
            'exercise_id' => $exercise->id,
        ])
        ->assertForbidden();
});

test('show returns workout template line', function () {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutTemplateLine::create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 0
    ]);

    actingAs($user)
        ->getJson(route('api.v1.workout-template-lines.show', $line))
        ->assertOk()
        ->assertJsonFragment(['id' => $line->id]);
});

test('update updates workout template line', function () {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutTemplateLine::create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 0
    ]);

    actingAs($user)
        ->putJson(route('api.v1.workout-template-lines.update', $line), [
            'order' => 10,
        ])
        ->assertOk()
        ->assertJsonFragment(['order' => 10]);
});

test('destroy deletes workout template line', function () {
    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $exercise = Exercise::factory()->create();
    $line = WorkoutTemplateLine::create([
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 0
    ]);

    actingAs($user)
        ->deleteJson(route('api.v1.workout-template-lines.destroy', $line))
        ->assertNoContent();

    expect(WorkoutTemplateLine::find($line->id))->toBeNull();
});
