<?php

use App\Models\User;
use App\Models\Injury;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('user can view injuries index', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $injury = Injury::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $response = $this->actingAs($user)->get(route('injuries.index'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Injuries/Index')
            ->has('activeInjuries', 1)
            ->has('injuryHistory', 0)
        );
});

test('user can create injury', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)->post(route('injuries.store'), [
        'body_part' => 'Left Shoulder',
        'severity' => 'medium',
        'status' => 'active',
        'occurred_at' => now()->format('Y-m-d'),
        'pain_level' => 7,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'user_id' => $user->id,
        'body_part' => 'Left Shoulder',
        'severity' => 'medium',
    ]);
});

test('user can update injury', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->put(route('injuries.update', $injury), [
        'body_part' => 'Updated Part',
        'severity' => 'low',
        'status' => 'healed',
        'occurred_at' => $injury->occurred_at->format('Y-m-d'),
        'healed_at' => now()->format('Y-m-d'),
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('injuries', [
        'id' => $injury->id,
        'body_part' => 'Updated Part',
        'status' => 'healed',
    ]);
});

test('user can delete injury', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $injury = Injury::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('injuries.destroy', $injury));

    $response->assertRedirect();
    $this->assertDatabaseMissing('injuries', ['id' => $injury->id]);
});

test('user cannot access others injuries', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    $injury = Injury::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->put(route('injuries.update', $injury), [
        'body_part' => 'Hacked',
        'severity' => 'low',
        'status' => 'active',
        'occurred_at' => now()->format('Y-m-d'),
    ]);

    $response->assertStatus(403);
});
