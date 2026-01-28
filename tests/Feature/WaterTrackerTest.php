<?php

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

test('water tracker page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('tools.water.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs')
            ->has('todayTotal')
            ->has('history')
            ->has('goal')
        );
});

test('user can log water consumption', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 500,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
    ]);
});

test('validation errors for water logging', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => '',
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('user can delete water log', function () {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    $this->assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('user cannot delete others water log', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user2->id]);

    $this->actingAs($user1)
        ->delete(route('tools.water.destroy', $log))
        ->assertForbidden();

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});
