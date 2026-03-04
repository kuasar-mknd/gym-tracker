<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->workoutTemplate = WorkoutTemplate::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $this->workoutTemplateLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $this->workoutTemplate->id,
    ]);
});

it('can list workout template sets', function (): void {
    WorkoutTemplateSet::factory()->count(3)->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
    ]);

    $response = $this->getJson('/api/v1/workout-template-sets');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('can list workout template sets with filter', function (): void {
    WorkoutTemplateSet::factory()->count(2)->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
    ]);

    $otherLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $this->workoutTemplate->id,
    ]);

    WorkoutTemplateSet::factory()->count(3)->create([
        'workout_template_line_id' => $otherLine->id,
    ]);

    $response = $this->getJson('/api/v1/workout-template-sets?filter[workout_template_line_id]='.$this->workoutTemplateLine->id);

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('can create a workout template set', function (): void {
    $data = [
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'reps' => 10,
        'weight' => 50,
        'is_warmup' => false,
        'order' => 1,
    ];

    $response = $this->postJson('/api/v1/workout-template-sets', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.reps', 10)
        ->assertJsonPath('data.weight', '50.00')
        ->assertJsonPath('data.is_warmup', false)
        ->assertJsonPath('data.order', 1);

    $this->assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'reps' => 10,
        'weight' => 50,
        'is_warmup' => false,
        'order' => 1,
    ]);
});

it('can auto-increment order when creating a workout template set', function (): void {
    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'order' => 5,
    ]);

    $data = [
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'reps' => 12,
        'weight' => 60,
        'is_warmup' => false,
    ];

    $response = $this->postJson('/api/v1/workout-template-sets', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.order', 6);
});

it('cannot create a workout template set for someone else\'s workout template line', function (): void {
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create([
        'user_id' => $otherUser->id,
    ]);
    $otherLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $otherTemplate->id,
    ]);

    $data = [
        'workout_template_line_id' => $otherLine->id,
        'reps' => 10,
        'weight' => 50,
        'is_warmup' => false,
        'order' => 1,
    ];

    $response = $this->postJson('/api/v1/workout-template-sets', $data);

    $response->assertStatus(422); // Because of the custom validation rule checking ownership
});

it('can show a workout template set', function (): void {
    $set = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'reps' => 8,
    ]);

    $response = $this->getJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $set->id)
        ->assertJsonPath('data.reps', 8);
});

it('cannot show someone else\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create([
        'user_id' => $otherUser->id,
    ]);
    $otherLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $otherTemplate->id,
    ]);
    $otherSet = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $otherLine->id,
    ]);

    $response = $this->getJson('/api/v1/workout-template-sets/'.$otherSet->id);

    $response->assertStatus(403);
});

it('can update a workout template set', function (): void {
    $set = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
        'reps' => 8,
        'weight' => 40,
        'is_warmup' => true,
        'order' => 1,
    ]);

    $data = [
        'reps' => 10,
        'weight' => 45,
        'is_warmup' => false,
        'order' => 2,
    ];

    $response = $this->putJson('/api/v1/workout-template-sets/'.$set->id, $data);

    $response->assertStatus(200)
        ->assertJsonPath('data.reps', 10)
        ->assertJsonPath('data.weight', '45.00')
        ->assertJsonPath('data.is_warmup', false)
        ->assertJsonPath('data.order', 2);

    $this->assertDatabaseHas('workout_template_sets', [
        'id' => $set->id,
        'reps' => 10,
        'weight' => 45,
        'is_warmup' => false,
        'order' => 2,
    ]);
});

it('cannot update someone else\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create([
        'user_id' => $otherUser->id,
    ]);
    $otherLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $otherTemplate->id,
    ]);
    $otherSet = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $otherLine->id,
    ]);

    $data = [
        'reps' => 10,
    ];

    $response = $this->putJson('/api/v1/workout-template-sets/'.$otherSet->id, $data);

    $response->assertStatus(403);
});

it('can delete a workout template set', function (): void {
    $set = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $this->workoutTemplateLine->id,
    ]);

    $response = $this->deleteJson('/api/v1/workout-template-sets/'.$set->id);

    $response->assertStatus(204);

    $this->assertDatabaseMissing('workout_template_sets', [
        'id' => $set->id,
    ]);
});

it('cannot delete someone else\'s workout template set', function (): void {
    $otherUser = User::factory()->create();
    $otherTemplate = WorkoutTemplate::factory()->create([
        'user_id' => $otherUser->id,
    ]);
    $otherLine = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $otherTemplate->id,
    ]);
    $otherSet = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $otherLine->id,
    ]);

    $response = $this->deleteJson('/api/v1/workout-template-sets/'.$otherSet->id);

    $response->assertStatus(403);
});
