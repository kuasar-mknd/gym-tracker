<?php

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

test('water tracker index page is displayed for authenticated user', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    travelTo(now()->startOfDay()->addHours(12));

    $todayLog = WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 500,
        'consumed_at' => now(),
    ]);

    WaterLog::factory()->create([
        'user_id' => $user->id,
        'amount' => 300,
        'consumed_at' => now()->subDay(),
    ]);

    get(route('tools.water.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Tools/WaterTracker')
            ->has('logs', 1)
            ->where('logs.0.id', $todayLog->id)
            ->where('todayTotal', fn ($val) => (int) $val === 500)
            ->has('history', 7)
            ->where('goal', 2500)
        );
});

test('water tracker index redirects unauthenticated users', function (): void {
    get(route('tools.water.index'))
        ->assertRedirect(route('login'));
});

test('user can add water log', function (): void {
    $user = User::factory()->create();
    actingAs($user);
    travelTo(now());

    $data = [
        'amount' => 300,
        'consumed_at' => now()->toDateTimeString(),
    ];

    post(route('tools.water.store'), $data)
        ->assertRedirect();

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $user->id,
        'amount' => 300,
    ]);
});

test('water log creation requires amount', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    post(route('tools.water.store'), [
        'consumed_at' => now()->toDateTimeString(),
    ])
    ->assertSessionHasErrors('amount');
});

test('water log creation requires consumed_at', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    post(route('tools.water.store'), [
        'amount' => 500,
    ])
    ->assertSessionHasErrors('consumed_at');
});

test('user can delete their own water log', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $log = WaterLog::factory()->create([
        'user_id' => $user->id,
    ]);

    delete(route('tools.water.destroy', $log))
        ->assertRedirect();

    $this->assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('user cannot delete another users water log', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    delete(route('tools.water.destroy', $log))
        ->assertForbidden();

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});
