<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('water tracker index is displayed for authenticated user', function () {
    $user = User::factory()->create();

    // Create some logs for today
    WaterLog::factory()->count(3)->create([
        'user_id' => $user->id,
        'consumed_at' => now(),
    ]);

    actingAs($user)
        ->get(route('tools.water.index'))
        ->assertStatus(200)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs', 3)
            ->has('todayTotal')
            ->has('history')
            ->has('goal')
        );
});

test('unauthenticated user cannot access water tracker', function () {
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

test('validation rules for logging water', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 'not-an-integer',
            'consumed_at' => 'not-a-date',
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);

    actingAs($user)
        ->post(route('tools.water.store'), [
             // missing fields
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

test('user cannot delete others water log', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $logA = WaterLog::factory()->create(['user_id' => $userA->id]);

    actingAs($userB)
        ->delete(route('tools.water.destroy', $logA))
        ->assertStatus(403);

    assertDatabaseHas('water_logs', [
        'id' => $logA->id,
    ]);
});
