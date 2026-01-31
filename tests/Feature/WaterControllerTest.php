<?php

use App\Models\User;
use App\Models\WaterLog;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('authenticated user can view water tracker', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('tools.water.index'))
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Tools/WaterTracker')
                ->has('logs')
                ->has('todayTotal')
                ->has('history')
                ->has('goal')
        );
});

test('unauthenticated user cannot view water tracker', function (): void {
    get(route('tools.water.index'))
        ->assertRedirect(route('login'));
});

test('authenticated user can store water log', function (): void {
    $user = User::factory()->create();
    $data = [
        'amount' => 500,
        'consumed_at' => now()->toDateTimeString(),
    ];

    actingAs($user)
        ->post(route('tools.water.store'), $data)
        ->assertRedirect();

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
    ]);
});

test('store water log requires validation', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [])
        ->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('store water log requires positive amount', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 0,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertSessionHasErrors(['amount']);
});

test('authenticated user can delete their own water log', function (): void {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    $this->assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('user cannot delete other users water log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertForbidden();

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});

test('index shows correct today total', function (): void {
    $user = User::factory()->create();

    // Create logs for today
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 200,
        'consumed_at' => Carbon::now(),
    ]);
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 300,
        'consumed_at' => Carbon::now(),
    ]);

    // Create log for yesterday (should not be in todayTotal)
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 500,
        'consumed_at' => Carbon::yesterday(),
    ]);

    actingAs($user)
        ->get(route('tools.water.index'))
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('todayTotal', 500)
                ->has('logs', 2)
        );
});
