<?php

declare(strict_types=1);

use App\Models\IntervalTimer;
use App\Models\User;
use function Pest\Laravel\actingAs;

test('index returns user interval timers', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    IntervalTimer::factory()->count(3)->create(['user_id' => $user->id]);
    IntervalTimer::factory()->count(2)->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.interval-timers.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('store creates a new interval timer', function (): void {
    $user = User::factory()->create();

    $data = [
        'name' => 'Tabata',
        'work_seconds' => 20,
        'rest_seconds' => 10,
        'rounds' => 8,
        'warmup_seconds' => 60,
    ];

    actingAs($user)
        ->postJson(route('api.v1.interval-timers.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Tabata')
        ->assertJsonPath('data.work_seconds', 20)
        ->assertJsonPath('data.rest_seconds', 10)
        ->assertJsonPath('data.rounds', 8)
        ->assertJsonPath('data.warmup_seconds', 60);

    $this->assertDatabaseHas('interval_timers', [
        'user_id' => $user->id,
        'name' => 'Tabata',
        'work_seconds' => 20,
    ]);
});

test('store requires valid data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('api.v1.interval-timers.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'work_seconds', 'rest_seconds', 'rounds']);
});

test('show returns a specific interval timer', function (): void {
    $user = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->getJson(route('api.v1.interval-timers.show', $timer))
        ->assertOk()
        ->assertJsonPath('data.id', $timer->id)
        ->assertJsonPath('data.name', $timer->name);
});

test('user cannot view another users interval timer', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->getJson(route('api.v1.interval-timers.show', $timer))
        ->assertForbidden();
});

test('update modifies an existing interval timer', function (): void {
    $user = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);

    $data = [
        'name' => 'Updated Tabata',
        'work_seconds' => 30,
        'rest_seconds' => 15,
        'rounds' => 10,
        'warmup_seconds' => 120,
    ];

    actingAs($user)
        ->putJson(route('api.v1.interval-timers.update', $timer), $data)
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Tabata');

    $this->assertDatabaseHas('interval_timers', [
        'id' => $timer->id,
        'name' => 'Updated Tabata',
        'work_seconds' => 30,
    ]);
});

test('update validates data', function (): void {
    $user = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->putJson(route('api.v1.interval-timers.update', $timer), [
            'name' => '', // Invalid
            'work_seconds' => -5, // Invalid
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'work_seconds']);
});

test('user cannot update another users interval timer', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'name' => 'Hacked Timer',
        'work_seconds' => 30,
        'rest_seconds' => 15,
        'rounds' => 10,
    ];

    actingAs($user)
        ->putJson(route('api.v1.interval-timers.update', $timer), $data)
        ->assertForbidden();
});

test('destroy deletes an interval timer', function (): void {
    $user = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.interval-timers.destroy', $timer))
        ->assertNoContent();

    $this->assertDatabaseMissing('interval_timers', [
        'id' => $timer->id,
    ]);
});

test('user cannot delete another users interval timer', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->deleteJson(route('api.v1.interval-timers.destroy', $timer))
        ->assertForbidden();

    $this->assertDatabaseHas('interval_timers', [
        'id' => $timer->id,
    ]);
});

test('unauthenticated users cannot access endpoints', function (): void {
    $timer = IntervalTimer::factory()->create();

    $this->getJson(route('api.v1.interval-timers.index'))
        ->assertUnauthorized();

    $this->postJson(route('api.v1.interval-timers.store'), [])
        ->assertUnauthorized();

    $this->getJson(route('api.v1.interval-timers.show', $timer))
        ->assertUnauthorized();

    $this->putJson(route('api.v1.interval-timers.update', $timer), [])
        ->assertUnauthorized();

    $this->deleteJson(route('api.v1.interval-timers.destroy', $timer))
        ->assertUnauthorized();
});
