<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('index is displayed for authenticated user', function () {
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

test('user can log water', function () {
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

test('water log requires valid data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => null,
            'consumed_at' => null,
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);

    $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 'not-an-integer',
            'consumed_at' => 'not-a-date',
        ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);

    $this->actingAs($user)
        ->post(route('tools.water.store'), [
            'amount' => 0, // min:1
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertSessionHasErrors(['amount']);
});

test('user cannot delete others water log', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->delete(route('tools.water.destroy', $log))
        ->assertForbidden();

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});

test('unauthenticated user cannot access water tracker', function () {
    $this->get(route('tools.water.index'))
        ->assertRedirect(route('login'));

    $this->post(route('tools.water.store'), [])
        ->assertRedirect(route('login'));

    $log = WaterLog::factory()->create();
    $this->delete(route('tools.water.destroy', $log))
        ->assertRedirect(route('login'));
});
