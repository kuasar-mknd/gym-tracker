<?php

declare(strict_types=1);

use App\Models\SleepLog;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('can render sleep tracker page', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('tools.sleep.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tools/SleepTracker')
            ->has('logs')
            ->has('history')
        );
});

test('can log sleep', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('tools.sleep.store'), [
            'date' => now()->format('Y-m-d'),
            'duration_minutes' => 480,
            'quality' => 4,
            'notes' => 'Good sleep',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('sleep_logs', [
        'user_id' => $user->id,
        'duration_minutes' => 480,
        'quality' => 4,
    ]);
});

test('can delete sleep log', function () {
    $user = User::factory()->create();
    $log = SleepLog::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('tools.sleep.destroy', $log))
        ->assertRedirect();

    $this->assertDatabaseMissing('sleep_logs', ['id' => $log->id]);
});

test('cannot delete others sleep log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = SleepLog::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->delete(route('tools.sleep.destroy', $log))
        ->assertForbidden();
});
