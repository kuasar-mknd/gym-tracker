<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

test('can list workout template lines for authenticated user', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    WorkoutTemplateLine::factory(3)->create([
        'workout_template_id' => $template->id,
    ]);

    // Another user's template
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    WorkoutTemplateLine::factory(2)->create([
        'workout_template_id' => $otherTemplate->id,
    ]);

    $response = getJson('/api/v1/workout-template-lines');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can filter workout template lines by workout_template_id', function (): void {
    $template1 = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    WorkoutTemplateLine::factory(2)->create([
        'workout_template_id' => $template1->id,
    ]);

    $template2 = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    WorkoutTemplateLine::factory(3)->create([
        'workout_template_id' => $template2->id,
    ]);

    $response = getJson('/api/v1/workout-template-lines?filter[workout_template_id]=' . $template1->id);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can create a workout template line', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

    $response = postJson('/api/v1/workout-template-lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.order', 1);

    assertDatabaseHas('workout_template_lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ]);
});

test('cannot create a workout template line with invalid data', function (): void {
    $response = postJson('/api/v1/workout-template-lines', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_template_id', 'exercise_id']);
});

test('cannot create a workout template line for another user\'s template', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $exercise = Exercise::factory()->create(['user_id' => $this->user->id]);

    $response = postJson('/api/v1/workout-template-lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_template_id']);
});

test('cannot create a workout template line with another user\'s exercise', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $otherUser->id]);

    $response = postJson('/api/v1/workout-template-lines', [
        'workout_template_id' => $template->id,
        'exercise_id' => $exercise->id,
        'order' => 1,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('can view a specific workout template line', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = getJson('/api/v1/workout-template-lines/' . $line->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $line->id);
});

test('cannot view another user\'s workout template line', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = getJson('/api/v1/workout-template-lines/' . $line->id);

    $response->assertForbidden();
});

test('can update a workout template line', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $newExercise = Exercise::factory()->create(['user_id' => $this->user->id]);

    $response = putJson('/api/v1/workout-template-lines/' . $line->id, [
        'exercise_id' => $newExercise->id,
        'order' => 5,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.order', 5);

    assertDatabaseHas('workout_template_lines', [
        'id' => $line->id,
        'exercise_id' => $newExercise->id,
        'order' => 5,
    ]);
});

test('cannot update a workout template line with invalid exercise', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = putJson('/api/v1/workout-template-lines/' . $line->id, [
        'exercise_id' => 999999,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('cannot update another user\'s workout template line', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $newExercise = Exercise::factory()->create(['user_id' => $this->user->id]);

    $response = putJson('/api/v1/workout-template-lines/' . $line->id, [
        'exercise_id' => $newExercise->id,
        'order' => 5,
    ]);

    $response->assertForbidden();
});

test('can delete a workout template line', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = deleteJson('/api/v1/workout-template-lines/' . $line->id);

    $response->assertNoContent();

    assertDatabaseMissing('workout_template_lines', [
        'id' => $line->id,
    ]);
});

test('cannot delete another user\'s workout template line', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = deleteJson('/api/v1/workout-template-lines/' . $line->id);

    $response->assertForbidden();

    assertDatabaseHas('workout_template_lines', [
        'id' => $line->id,
    ]);
});
