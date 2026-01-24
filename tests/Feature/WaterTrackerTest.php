<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('displays the water tracker page', function () {
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

test('creates a water log', function () {
    $user = User::factory()->create();

    $data = [
        'amount' => 500,
        'consumed_at' => now()->toDateTimeString(),
    ];

    actingAs($user)
        ->post(route('tools.water.store'), $data)
        ->assertRedirect();

    assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
    ]);
});

test('requires valid data to create a log', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 'not-an-integer',
            'consumed_at' => 'not-a-date',
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);

    assertDatabaseCount('water_logs', 0);
});

test('deletes a water log', function () {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('prevents deleting another users water log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertStatus(403);

    assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});

test('redirects unauthenticated users', function () {
    get(route('tools.water.index'))
        ->assertRedirect(route('login'));

    post(route('tools.water.store'), [])
        ->assertRedirect(route('login'));

    // Create a dummy log to try deleting
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    delete(route('tools.water.destroy', $log))
        ->assertRedirect(route('login'));
});
