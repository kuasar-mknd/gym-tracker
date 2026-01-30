<?php

use App\Models\User;
use App\Models\Injury;
use Inertia\Testing\AssertableInertia as Assert;

test('injuries page renders', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('injuries.index'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Injuries/Index')
            ->has('activeInjuries')
            ->has('history')
        );
});

test('user can store a new injury', function () {
    $user = User::factory()->create();

    $data = [
        'body_part' => 'Left Knee',
        'description' => 'Pain when walking',
        'status' => 'active',
        'pain_level' => 7,
        'occurred_at' => now()->format('Y-m-d'),
        'notes' => 'Rest and ice',
    ];

    $response = $this->actingAs($user)->post(route('injuries.store'), $data);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'user_id' => $user->id,
        'body_part' => 'Left Knee',
        'pain_level' => 7,
        'status' => 'active',
    ]);
});

test('user can update an injury', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $data = [
        'body_part' => 'Left Knee Updated',
        'description' => 'Better now',
        'status' => 'active',
        'pain_level' => 3,
        'occurred_at' => $injury->occurred_at->format('Y-m-d'),
        'notes' => 'Continuing rehab',
    ];

    $response = $this->actingAs($user)->put(route('injuries.update', $injury), $data);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'id' => $injury->id,
        'body_part' => 'Left Knee Updated',
        'pain_level' => 3,
    ]);
});

test('updating status to healed sets healed_at', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $data = [
        'body_part' => $injury->body_part,
        'description' => $injury->description,
        'status' => 'healed',
        'pain_level' => 1,
        'occurred_at' => $injury->occurred_at->format('Y-m-d'),
    ];

    $response = $this->actingAs($user)->put(route('injuries.update', $injury), $data);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'id' => $injury->id,
        'status' => 'healed',
    ]);

    $updatedInjury = $injury->fresh();
    expect($updatedInjury->healed_at)->not->toBeNull();
});

test('user can delete an injury', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('injuries.destroy', $injury));

    $response->assertRedirect();
    $this->assertDatabaseMissing('injuries', ['id' => $injury->id]);
});

test('user cannot update others injury', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->put(route('injuries.update', $injury), [
        'body_part' => 'Hacked',
        'status' => 'active',
        'pain_level' => 1,
        'occurred_at' => now()->format('Y-m-d'),
    ]);

    $response->assertStatus(403);
});

test('user cannot delete others injury', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete(route('injuries.destroy', $injury));

    $response->assertStatus(403);
});
