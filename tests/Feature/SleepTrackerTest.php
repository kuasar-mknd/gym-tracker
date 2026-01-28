<?php

use App\Models\SleepLog;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('sleep tracker page can be rendered', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('tools.sleep.index'));

    $response->assertStatus(200);
});

test('can create a sleep log', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->post(route('tools.sleep.store'), [
            'date' => '2023-10-27',
            'duration_minutes' => 480,
            'quality' => 4,
            'notes' => 'Good sleep',
        ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('sleep_logs', [
        'user_id' => $user->id,
        'date' => '2023-10-27 00:00:00',
        'duration_minutes' => 480,
        'quality' => 4,
        'notes' => 'Good sleep',
    ]);
});

test('validates input', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->post(route('tools.sleep.store'), [
            'date' => 'not-a-date',
            'duration_minutes' => 'not-a-number',
        ]);

    $response->assertSessionHasErrors(['date', 'duration_minutes']);
});

test('can delete a sleep log', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $log = SleepLog::factory()->create(['user_id' => $user->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tools.sleep.destroy', $log));

    $response->assertRedirect();
    $this->assertDatabaseMissing('sleep_logs', ['id' => $log->id]);
});

test('cannot delete another users sleep log', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $otherUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    $log = SleepLog::factory()->create(['user_id' => $otherUser->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('tools.sleep.destroy', $log));

    $response->assertStatus(403);
    $this->assertDatabaseHas('sleep_logs', ['id' => $log->id]);
});

test('history data is correct', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);
    // Create log for today
    SleepLog::factory()->create([
        'user_id' => $user->id,
        'date' => Carbon::now()->toDateString(),
        'duration_minutes' => 480,
    ]);

    $response = $this
        ->actingAs($user)
        ->get(route('tools.sleep.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tools/SleepTracker')
        ->has('history', 7)
        ->where('history.6.total', 480)
        ->where('history.6.date', Carbon::now()->toDateString())
    );
});
