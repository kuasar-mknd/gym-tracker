<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
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

test('can list workout template sets for authenticated user', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory(3)->create([
        'workout_template_line_id' => $line->id,
    ]);

    // Another user's template sets
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $otherLine = WorkoutTemplateLine::factory()->create(['workout_template_id' => $otherTemplate->id]);
    WorkoutTemplateSet::factory(2)->create([
        'workout_template_line_id' => $otherLine->id,
    ]);

    $response = getJson('/api/v1/workout-template-sets');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('can filter workout template sets by workout_template_line_id', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);

    $line1 = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory(2)->create([
        'workout_template_line_id' => $line1->id,
    ]);

    $line2 = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory(3)->create([
        'workout_template_line_id' => $line2->id,
    ]);

    $response = getJson('/api/v1/workout-template-sets?filter[workout_template_line_id]='.$line1->id);

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('can create a workout template set', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = postJson('/api/v1/workout-template-sets', [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.reps', 10)
        ->assertJsonPath('data.weight', '50.50')
        ->assertJsonPath('data.is_warmup', false)
        ->assertJsonPath('data.order', 1);

    assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ]);
});

test('cannot create a workout template set with invalid data', function (): void {
    $response = postJson('/api/v1/workout-template-sets', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_template_line_id', 'is_warmup']);
});

test('cannot create a workout template set for another user\'s template line', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = postJson('/api/v1/workout-template-sets', [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ]);

    $response->assertForbidden();
});

test('can view a specific workout template set', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = getJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $set->id);
});

test('cannot view another user\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = getJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertForbidden();
});

test('can update a workout template set', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line->id,
        'reps' => 5,
        'weight' => 20,
        'is_warmup' => true,
        'order' => 1,
    ]);

    $response = putJson('/api/v1/workout-template-sets/'.$set->id, [
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
        'order' => 2,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.reps', 8)
        ->assertJsonPath('data.weight', '60.00')
        ->assertJsonPath('data.is_warmup', false)
        ->assertJsonPath('data.order', 2);

    assertDatabaseHas('workout_template_sets', [
        'id' => $set->id,
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
        'order' => 2,
    ]);
});

test('cannot update a workout template set with invalid data', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = putJson('/api/v1/workout-template-sets/'.$set->id, [
        'reps' => -5,
        'weight' => -10,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['reps', 'weight']);
});

test('cannot update another user\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = putJson('/api/v1/workout-template-sets/'.$set->id, [
        'reps' => 8,
        'weight' => 60,
    ]);

    $response->assertForbidden();
});

test('can delete a workout template set', function (): void {
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = deleteJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertNoContent();

    assertDatabaseMissing('workout_template_sets', [
        'id' => $set->id,
    ]);
});

test('cannot delete another user\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $response = deleteJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertForbidden();

    assertDatabaseHas('workout_template_sets', [
        'id' => $set->id,
    ]);
});
