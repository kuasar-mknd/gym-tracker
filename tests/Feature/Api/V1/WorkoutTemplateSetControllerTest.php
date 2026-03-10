<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('unauthenticated user cannot access workout template sets', function (): void {
    $this->getJson(route('api.v1.workout-template-sets.index'))->assertUnauthorized();
    $this->postJson(route('api.v1.workout-template-sets.store'), [])->assertUnauthorized();

    $set = WorkoutTemplateSet::factory()->create();

    $this->getJson(route('api.v1.workout-template-sets.show', $set))->assertUnauthorized();
    $this->putJson(route('api.v1.workout-template-sets.update', $set), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.workout-template-sets.destroy', $set))->assertUnauthorized();
});

test('user can list own workout template sets', function (): void {
    Sanctum::actingAs($this->user);

    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory()->count(3)->create(['workout_template_line_id' => $line->id]);

    // Create other user's template set
    $otherTemplate = WorkoutTemplate::factory()->create();
    $otherLine = WorkoutTemplateLine::factory()->create(['workout_template_id' => $otherTemplate->id]);
    WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $otherLine->id]);

    $response = $this->getJson(route('api.v1.workout-template-sets.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can filter list by workout template line id', function (): void {
    Sanctum::actingAs($this->user);

    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);

    $line1 = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory()->count(2)->create(['workout_template_line_id' => $line1->id]);

    $line2 = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    WorkoutTemplateSet::factory()->count(3)->create(['workout_template_line_id' => $line2->id]);

    $response = $this->getJson(route('api.v1.workout-template-sets.index', ['filter[workout_template_line_id]' => $line1->id]));

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can create workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $data = [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ];

    $response = $this->postJson(route('api.v1.workout-template-sets.store'), $data);

    $response->assertCreated()
        ->assertJsonPath('data.reps', 10)
        ->assertJsonPath('data.weight', '50.50')
        ->assertJsonPath('data.is_warmup', false)
        ->assertJsonPath('data.order', 1);

    $this->assertDatabaseHas('workout_template_sets', [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ]);
});

test('create template set requires workout_template_line_id', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->postJson(route('api.v1.workout-template-sets.store'), [
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_template_line_id']);
});

test('create template set requires is_warmup', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = $this->postJson(route('api.v1.workout-template-sets.store'), [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['is_warmup']);
});

test('create template set validates data types', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $response = $this->postJson(route('api.v1.workout-template-sets.store'), [
        'workout_template_line_id' => $line->id,
        'reps' => -5,
        'weight' => -10.5,
        'is_warmup' => 'not-a-boolean',
        'order' => -1,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reps',
            'weight',
            'is_warmup',
            'order',
        ]);
});

test('user cannot create template set for other users template line', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);

    $data = [
        'workout_template_line_id' => $line->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => false,
        'order' => 1,
    ];

    $this->postJson(route('api.v1.workout-template-sets.store'), $data)
        ->assertForbidden();
});

test('user can show own workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $this->getJson(route('api.v1.workout-template-sets.show', $set))
        ->assertOk()
        ->assertJsonPath('data.id', $set->id);
});

test('user cannot show other users workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $this->getJson(route('api.v1.workout-template-sets.show', $set))
        ->assertForbidden();
});

test('user can update own workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line->id,
        'reps' => 5,
        'weight' => 20.0,
        'is_warmup' => false,
        'order' => 1,
    ]);

    $data = [
        'reps' => 12,
        'weight' => 60.0,
        'is_warmup' => true,
        'order' => 2,
    ];

    $this->putJson(route('api.v1.workout-template-sets.update', $set), $data)
        ->assertOk()
        ->assertJsonPath('data.reps', 12)
        ->assertJsonPath('data.weight', '60.00')
        ->assertJsonPath('data.is_warmup', true)
        ->assertJsonPath('data.order', 2);

    $this->assertDatabaseHas('workout_template_sets', [
        'id' => $set->id,
        'reps' => 12,
        'weight' => 60.0,
        'is_warmup' => true,
        'order' => 2,
    ]);
});

test('update validates data types', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $data = [
        'reps' => -5,
        'weight' => -10.5,
        'is_warmup' => 'not-a-boolean',
        'order' => -1,
    ];

    $this->putJson(route('api.v1.workout-template-sets.update', $set), $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reps',
            'weight',
            'is_warmup',
            'order',
        ]);
});

test('user cannot update other users workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $data = [
        'reps' => 12,
        'weight' => 60.0,
        'is_warmup' => true,
    ];

    $this->putJson(route('api.v1.workout-template-sets.update', $set), $data)
        ->assertForbidden();
});

test('user can delete own workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $template = WorkoutTemplate::factory()->create(['user_id' => $this->user->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $this->deleteJson(route('api.v1.workout-template-sets.destroy', $set))
        ->assertNoContent();

    $this->assertDatabaseMissing('workout_template_sets', ['id' => $set->id]);
});

test('user cannot delete other users workout template set', function (): void {
    Sanctum::actingAs($this->user);
    $otherUser = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutTemplateLine::factory()->create(['workout_template_id' => $template->id]);
    $set = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $line->id]);

    $this->deleteJson(route('api.v1.workout-template-sets.destroy', $set))
        ->assertForbidden();

    $this->assertDatabaseHas('workout_template_sets', ['id' => $set->id]);
});
