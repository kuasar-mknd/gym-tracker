<?php

use App\Models\User;
use App\Models\FastingLog;

test('fasting page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('fasting.index'));

    $response->assertOk();
});

test('user can start a fast', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('fasting.store'), [
            'target_duration_hours' => 16,
            'method' => '16:8',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('fasting_logs', [
        'user_id' => $user->id,
        'method' => '16:8',
        'end_time' => null,
    ]);
});

test('user cannot start a fast if one is active', function () {
    $user = User::factory()->create();
    FastingLog::create([
        'user_id' => $user->id,
        'start_time' => now(),
        'method' => '16:8',
        'target_duration_hours' => 16
    ]);

    $response = $this
        ->actingAs($user)
        ->post(route('fasting.store'), [
            'target_duration_hours' => 18,
            'method' => '18:6',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    // Ensure only 1 active log
    expect($user->fastingLogs()->count())->toBe(1);
});

test('user can end a fast', function () {
    $user = User::factory()->create();
    $log = FastingLog::create([
        'user_id' => $user->id,
        'start_time' => now()->subHours(10),
        'method' => '16:8',
        'target_duration_hours' => 16
    ]);

    $response = $this
        ->actingAs($user)
        ->put(route('fasting.update', $log), [
            'end_time' => now()->toDateTimeString(),
        ]);

    $response->assertRedirect();
    $log->refresh();
    expect($log->end_time)->not->toBeNull();
});

test('user can delete a fast', function () {
    $user = User::factory()->create();
    $log = FastingLog::create([
        'user_id' => $user->id,
        'start_time' => now(),
        'method' => '16:8',
        'target_duration_hours' => 16
    ]);

    $response = $this
        ->actingAs($user)
        ->delete(route('fasting.destroy', $log));

    $response->assertRedirect();
    $this->assertDatabaseMissing('fasting_logs', ['id' => $log->id]);
});
