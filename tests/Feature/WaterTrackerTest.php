<?php

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

test('unauthenticated users are redirected to login', function () {
    $response = $this->get(route('tools.water.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view water tracker', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('tools.water.index'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs')
            ->has('todayTotal')
            ->has('history')
            ->has('goal')
        );
});

test('users can store water log', function () {
    $user = User::factory()->create();
    $date = now()->format('Y-m-d H:i:s');

    $response = $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 500,
            'consumed_at' => $date,
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 500,
        'consumed_at' => $date,
    ]);
});

test('store water log validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 'not-an-integer',
            'consumed_at' => 'not-a-date',
        ]);

    $response->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('users can delete their own water log', function () {
    $user = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->delete(route('tools.water.destroy', $log));

    $response->assertRedirect();
    $this->assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('users cannot delete others water log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)
        ->delete(route('tools.water.destroy', $log));

    $response->assertStatus(403);
    $this->assertDatabaseHas('water_logs', ['id' => $log->id]);
});
