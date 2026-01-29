<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    actingAs($this->user);
});

test('can view water tracker page', function (): void {
    // Create some logs for today
    WaterLog::factory()->create([
        'user_id' => $this->user->id,
        'amount' => 500,
        'consumed_at' => now(),
    ]);

    get(route('tools.water.index'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tools/WaterTracker')
            ->has('logs', 1)
            ->has('todayTotal')
            ->has('history')
            ->has('goal')
            ->where('goal', 2500)
            ->where('todayTotal', 500)
        );
});

test('can add water log', function (): void {
    $data = [
        'amount' => 250,
        'consumed_at' => now()->toDateTimeString(),
    ];

    post(route('tools.water.store'), $data)
        ->assertRedirect();

    assertDatabaseHas('water_logs', [
        'user_id' => $this->user->id,
        'amount' => 250,
        // We don't check exact timestamp as it might differ slightly, but we check existence
    ]);
});

test('validates water log input', function (): void {
    post(route('tools.water.store'), [
        'amount' => 'not-a-number',
        'consumed_at' => 'not-a-date',
    ])
        ->assertSessionHasErrors(['amount', 'consumed_at']);
});

test('can delete water log', function (): void {
    $log = WaterLog::factory()->create([
        'user_id' => $this->user->id,
    ]);

    delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('cannot delete others water log', function (): void {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    delete(route('tools.water.destroy', $log))
        ->assertStatus(403);

    assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});
