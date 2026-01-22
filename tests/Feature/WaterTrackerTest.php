<?php

use App\Models\User;
use App\Models\WaterLog;
use Inertia\Testing\AssertableInertia;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('water tracker index page is displayed for authenticated user', function (): void {
    // Freeze time to ensure "today" is consistent between test setup and controller query
    $this->travelTo(now()->startOfDay()->addHours(12));

    $todayLog = WaterLog::factory()->create([
        'user_id' => $this->user->id,
        'amount' => 500,
        'consumed_at' => now(),
    ]);

    // Create a log for yesterday to ensure it's NOT included in 'logs' but IS in 'history'
    WaterLog::factory()->create([
        'user_id' => $this->user->id,
        'amount' => 300,
        'consumed_at' => now()->subDay(),
    ]);

    $response = $this->get(route('tools.water.index'));

    $response->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Tools/WaterTracker')
        ->has('logs', 1)
        ->where('logs.0.id', $todayLog->id)
        ->where('todayTotal', 500)
        ->has('history', 7) // 7 days of history including today
        ->where('goal', 2500)
    );
});

test('water tracker index redirects unauthenticated users', function (): void {
    auth()->logout();

    $response = $this->get(route('tools.water.index'));

    $response->assertRedirect(route('login'));
});

test('user can add water log', function (): void {
    $this->travelTo(now());

    $data = [
        'amount' => 300,
        'consumed_at' => now()->toDateTimeString(),
    ];

    $response = $this->post(route('tools.water.store'), $data);

    $response->assertRedirect();

    $this->assertDatabaseHas('water_logs', [
        'user_id' => $this->user->id,
        'amount' => 300,
    ]);
});

test('water log creation requires amount', function (): void {
    $response = $this->post(route('tools.water.store'), [
        'consumed_at' => now()->toDateTimeString(),
    ]);

    $response->assertSessionHasErrors('amount');
});

test('water log creation requires consumed_at', function (): void {
    $response = $this->post(route('tools.water.store'), [
        'amount' => 500,
    ]);

    $response->assertSessionHasErrors('consumed_at');
});

test('user can delete their own water log', function (): void {
    $log = WaterLog::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->delete(route('tools.water.destroy', $log));

    $response->assertRedirect();

    $this->assertDatabaseMissing('water_logs', [
        'id' => $log->id,
    ]);
});

test('user cannot delete another users water log', function (): void {
    $otherUser = User::factory()->create();
    $log = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->delete(route('tools.water.destroy', $log));

    $response->assertForbidden();

    $this->assertDatabaseHas('water_logs', [
        'id' => $log->id,
    ]);
});
