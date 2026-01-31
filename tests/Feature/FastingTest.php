<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

test('user can view fasting page', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('tools.fasting.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tools/Fasting/Index')
            ->has('activeFast')
            ->has('history')
        );
});

test('user can start a fast', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.fasting.store'), [
            'start_time' => now()->format('Y-m-d H:i:s'),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ])
        ->assertRedirect();

    assertDatabaseHas('fasts', [
        'user_id' => $user->id,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

test('user cannot start a fast if one is already active', function (): void {
    $user = User::factory()->create();
    Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    actingAs($user)
        ->post(route('tools.fasting.store'), [
            'start_time' => now()->format('Y-m-d H:i:s'),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ])
        ->assertSessionHasErrors(['message']);
});

test('user can end a fast', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    actingAs($user)
        ->patch(route('tools.fasting.update', $fast), [
            'end_time' => now()->format('Y-m-d H:i:s'),
            'status' => 'completed',
        ])
        ->assertRedirect();

    assertDatabaseHas('fasts', [
        'id' => $fast->id,
        'status' => 'completed',
    ]);
});

test('user can delete a fast', function (): void {
    $user = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('tools.fasting.destroy', $fast))
        ->assertRedirect();

    assertDatabaseCount('fasts', 0);
});

test('user cannot access others fasts', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $fast = Fast::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    actingAs($user)
        ->patch(route('tools.fasting.update', $fast), [
            'status' => 'completed',
        ])
        ->assertStatus(403);

    actingAs($user)
        ->delete(route('tools.fasting.destroy', $fast))
        ->assertStatus(403);
});
