<?php

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

test('water tracker index is displayed for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('tools.water.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tools/WaterTracker')
        ->has('logs')
        ->has('todayTotal')
        ->has('history')
        ->has('goal')
    );
});

test('water tracker shows correct data', function () {
    $user = User::factory()->create();

    // Create some logs for today
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 500,
        'consumed_at' => now(),
    ]);
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 250,
        'consumed_at' => now(),
    ]);

    // Create a log for yesterday
    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 1000,
        'consumed_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('tools.water.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tools/WaterTracker')
        ->where('todayTotal', 750)
        ->has('logs', 2)
        ->has('history', 7) // 6 days ago + today
    );
});
