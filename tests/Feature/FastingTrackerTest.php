<?php

use App\Models\FastingLog;
use App\Models\User;

test('user can view fasting tracker', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('fasting.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tools/FastingTracker')
        ->has('activeFast')
        ->has('history')
    );
});

test('user can start a fast', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('fasting.store'), [
            'start_time' => now()->toDateTimeString(),
            'target_duration_hours' => 16,
            'type' => '16:8',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('fasting_logs', [
        'user_id' => $user->id,
        'type' => '16:8',
        'status' => 'active',
    ]);
});

test('user cannot start a second active fast', function () {
    $user = User::factory()->create();
    FastingLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $response = $this
        ->actingAs($user)
        ->post(route('fasting.store'), [
            'start_time' => now()->toDateTimeString(),
            'target_duration_hours' => 16,
            'type' => '16:8',
        ]);

    $response->assertSessionHasErrors();
    $this->assertCount(1, $user->fastingLogs);
});

test('user can end a fast', function () {
    $user = User::factory()->create();
    $fast = FastingLog::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'start_time' => now()->subHours(10),
    ]);

    $response = $this
        ->actingAs($user)
        ->put(route('fasting.update', $fast), [
            'end_time' => now()->toDateTimeString(),
            'status' => 'completed',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('fasting_logs', [
        'id' => $fast->id,
        'status' => 'completed',
    ]);
});

test('user can delete a fast', function () {
    $user = User::factory()->create();
    $fast = FastingLog::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->delete(route('fasting.destroy', $fast));

    $response->assertRedirect();
    $this->assertDatabaseMissing('fasting_logs', [
        'id' => $fast->id,
    ]);
});
