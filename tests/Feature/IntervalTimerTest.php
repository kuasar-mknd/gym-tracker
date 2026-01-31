<?php

use App\Models\IntervalTimer;
use App\Models\User;

test('user can view interval timer page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('tools.interval-timer.index'));

    $response->assertStatus(200);
});

test('user can create interval timer', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('tools.interval-timer.store'), [
        'name' => 'Test Timer',
        'work_seconds' => 30,
        'rest_seconds' => 15,
        'rounds' => 5,
        'warmup_seconds' => 10,
    ]);

    $response->assertRedirect(route('tools.interval-timer.index'));
    $this->assertDatabaseHas('interval_timers', [
        'user_id' => $user->id,
        'name' => 'Test Timer',
        'work_seconds' => 30,
        'rest_seconds' => 15,
        'rounds' => 5,
        'warmup_seconds' => 10,
    ]);
});

test('user can update interval timer', function () {
    $user = User::factory()->create();
    $timer = IntervalTimer::create([
        'user_id' => $user->id,
        'name' => 'Original Name',
        'work_seconds' => 20,
        'rest_seconds' => 10,
        'rounds' => 8,
        'warmup_seconds' => 5,
    ]);

    $response = $this->actingAs($user)->patch(route('tools.interval-timer.update', $timer), [
        'name' => 'Updated Name',
        'work_seconds' => 40,
        'rest_seconds' => 20,
        'rounds' => 4,
        'warmup_seconds' => 0,
    ]);

    $response->assertRedirect(route('tools.interval-timer.index'));
    $this->assertDatabaseHas('interval_timers', [
        'id' => $timer->id,
        'name' => 'Updated Name',
        'work_seconds' => 40,
    ]);
});

test('user can delete interval timer', function () {
    $user = User::factory()->create();
    $timer = IntervalTimer::create([
        'user_id' => $user->id,
        'name' => 'Delete Me',
        'work_seconds' => 20,
        'rest_seconds' => 10,
        'rounds' => 8,
        'warmup_seconds' => 5,
    ]);

    $response = $this->actingAs($user)->delete(route('tools.interval-timer.destroy', $timer));

    $response->assertRedirect(route('tools.interval-timer.index'));
    $this->assertDatabaseMissing('interval_timers', [
        'id' => $timer->id,
    ]);
});

test('user cannot update others timer', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $timer = IntervalTimer::create([
        'user_id' => $user1->id,
        'name' => 'User 1 Timer',
        'work_seconds' => 20,
        'rest_seconds' => 10,
        'rounds' => 8,
        'warmup_seconds' => 5,
    ]);

    $response = $this->actingAs($user2)->patch(route('tools.interval-timer.update', $timer), [
        'name' => 'Hacked Timer',
        'work_seconds' => 40,
        'rest_seconds' => 20,
        'rounds' => 4,
        'warmup_seconds' => 0,
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('interval_timers', [
        'id' => $timer->id,
        'name' => 'User 1 Timer',
    ]);
});
