<?php

declare(strict_types=1);

use App\Models\Injury;
use App\Models\User;

test('users can view their injuries', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('injuries.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Injuries/Index')
            ->has('injuries', 1)
        );
});

test('users can create injuries', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('injuries.store'), [
            'body_part' => 'Knee',
            'description' => 'Pain',
            'status' => 'active',
            'injured_at' => now()->format('Y-m-d'),
        ])
        ->assertRedirect(route('injuries.index'));

    $this->assertDatabaseHas('injuries', [
        'user_id' => $user->id,
        'body_part' => 'Knee',
    ]);
});

test('users can update injuries', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $this->actingAs($user)
        ->put(route('injuries.update', $injury), [
            'body_part' => 'Knee',
            'description' => 'Pain',
            'status' => 'healed',
            'injured_at' => now()->format('Y-m-d'),
        ])
        ->assertRedirect(route('injuries.index'));

    $this->assertDatabaseHas('injuries', [
        'id' => $injury->id,
        'status' => 'healed',
    ]);
});

test('users can delete injuries', function () {
    $user = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('injuries.destroy', $injury))
        ->assertRedirect(route('injuries.index'));

    $this->assertDatabaseMissing('injuries', [
        'id' => $injury->id,
    ]);
});

test('users cannot manage others injuries', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $injury = Injury::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->delete(route('injuries.destroy', $injury))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('injuries.update', $injury), [
            'body_part' => 'Shoulder',
            'description' => 'Shoulder',
            'status' => 'active',
            'injured_at' => now()->format('Y-m-d'),
        ])
        ->assertForbidden();
});
