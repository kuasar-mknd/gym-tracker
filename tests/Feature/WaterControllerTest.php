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

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('unauthenticated users cannot access water tracker', function (): void {
    get(route('tools.water.index'))->assertRedirect(route('login'));
    post(route('tools.water.store'))->assertRedirect(route('login'));
    $log = WaterLog::factory()->create();
    delete(route('tools.water.destroy', $log))->assertRedirect(route('login'));
});

test('authenticated users can visit water tracker', function (): void {
    $user = User::factory()->create();

    // Create some logs for today
    WaterLog::factory()->count(3)->create([
        'user_id' => $user->id,
        'consumed_at' => now(),
        'amount' => 500
    ]);

    actingAs($user)
        ->get(route('tools.water.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs', 3)
            ->where('todayTotal', 1500)
            ->has('history')
            ->where('goal', 2500)
        );
});

test('users can store a water log', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 330,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 330,
    ]);
});

test('users cannot store invalid water log', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => -100, // Invalid amount
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('users can delete their own water log', function (): void {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('users cannot delete others water log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertForbidden();

    assertDatabaseHas('water_logs', ['id' => $log->id]);
});
