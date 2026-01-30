<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia;

test('water tracker page is displayed for authenticated user', function (): void {
    $user = User::factory()->create();
    // Create some logs to verify data
    WaterLog::factory()->count(3)->create([
        'user_id' => $user->id,
        'consumed_at' => now(),
        'amount' => 500,
    ]);

    $response = $this->actingAs($user)->get(route('tools.water.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Tools/WaterTracker')
        ->has('logs', 3)
        ->has('todayTotal')
        ->where('todayTotal', 1500)
        ->has('history')
        ->has('goal')
    );
});

test('guests cannot view water tracker', function (): void {
    $response = $this->get(route('tools.water.index'));

    $response->assertRedirect(route('login'));
});

test('user can store water log', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('tools.water.store'), [
        'amount' => 250,
        'consumed_at' => now()->toDateTimeString(),
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 250,
    ]);
});

test('store validation rules', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('tools.water.store'), []);

    $response->assertSessionHasErrors(['amount', 'consumed_at']);

    $response = $this->actingAs($user)->post(route('tools.water.store'), [
        'amount' => 'not-an-integer',
        'consumed_at' => 'not-a-date',
    ]);

    $response->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('user can delete own water log', function (): void {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('tools.water.destroy', $log));

    $response->assertRedirect();
    $this->assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('user cannot delete others water log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($otherUser)->delete(route('tools.water.destroy', $log));

    $response->assertStatus(403);
    $this->assertDatabaseHas('water_logs', ['id' => $log->id]);
});
