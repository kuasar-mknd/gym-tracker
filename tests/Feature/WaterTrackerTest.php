<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('guests cannot access water tracker', function (): void {
    $response = $this->get(route('tools.water.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated user can view water tracker page', function (): void {
    $response = $this->actingAs($this->user)->get(route('tools.water.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tools/WaterTracker')
        ->has('logs')
        ->has('todayTotal')
        ->has('history')
        ->has('goal')
    );
});

test('authenticated user can add water log', function (): void {
    $data = [
        'amount' => 500,
        'consumed_at' => now()->format('Y-m-d H:i:s'),
    ];

    $response = $this->actingAs($this->user)->post(route('tools.water.store'), $data);

    $response->assertRedirect();
    $this->assertDatabaseHas('water_logs', [
        'user_id' => $this->user->id,
        'amount' => 500,
    ]);
});

test('cannot add water log with invalid data', function (): void {
    $data = [
        'amount' => -100, // Invalid
        'consumed_at' => 'not-a-date', // Invalid
    ];

    $response = $this->actingAs($this->user)->post(route('tools.water.store'), $data);

    $response->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('authenticated user can delete their own water log', function (): void {
    $log = WaterLog::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAs($this->user)->delete(route('tools.water.destroy', $log));

    $response->assertRedirect();
    $this->assertDatabaseMissing('water_logs', ['id' => $log->id]);
});

test('authenticated user cannot delete another users water log', function (): void {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->delete(route('tools.water.destroy', $log));

    $response->assertStatus(403);
    $this->assertDatabaseHas('water_logs', ['id' => $log->id]);
});
