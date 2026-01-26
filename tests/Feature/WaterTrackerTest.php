<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('authenticated user can view water tracker', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('tools.water.index'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs')
            ->has('todayTotal')
            ->has('history')
            ->has('goal')
        );
});

test('unauthenticated user is redirected to login', function () {
    get(route('tools.water.index'))
        ->assertRedirect(route('login'));
});

test('user can log water consumption', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 500,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
    ]);
});

test('water log requires valid data', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 'invalid',
            'consumed_at' => 'not-a-date',
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('user can delete their water log', function () {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('user cannot delete another users water log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertStatus(403);

    assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});
