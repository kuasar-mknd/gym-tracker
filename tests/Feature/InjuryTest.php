<?php

use App\Models\Injury;
use App\Models\User;

test('index page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('injuries.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Injuries/Index')
        ->has('injuries')
    );
});

test('injuries can be created', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('injuries.store'), [
            'body_part' => 'Knee',
            'description' => 'Pain when squatting',
            'severity' => 5,
            'status' => 'active',
            'occurred_at' => now()->format('Y-m-d'),
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'user_id' => $user->id,
        'body_part' => 'Knee',
        'severity' => 5,
    ]);
});

test('injuries can be updated', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->put(route('injuries.update', $injury), [
            'body_part' => 'Updated Knee',
            'description' => 'Updated description',
            'severity' => 2,
            'status' => 'recovering',
            'occurred_at' => $injury->occurred_at->format('Y-m-d'),
        ]);

    $response->assertRedirect();
    $injury->refresh();
    expect($injury->body_part)->toBe('Updated Knee');
    expect($injury->status)->toBe('recovering');
});

test('injuries can be deleted', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('injuries.destroy', $injury));

    $response->assertRedirect();
    $this->assertDatabaseMissing('injuries', [
        'id' => $injury->id,
    ]);
});

test('user cannot update others injury', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $otherUser->id]);

    $response = $this
        ->actingAs($user)
        ->put(route('injuries.update', $injury), [
            'body_part' => 'Hacked',
        ]);

    $response->assertForbidden();
});
