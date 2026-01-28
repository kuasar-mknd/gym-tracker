<?php

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

test('user can list supplement logs', function () {
    $user = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $user->id,
        'name' => 'Whey',
        'servings_remaining' => 50,
        'low_stock_threshold' => 10,
    ]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.supplement-logs.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('user can create a supplement log and stock decreases', function () {
    $user = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 100,
        'low_stock_threshold' => 10,
    ]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.supplement-logs.store'), [
            'supplement_id' => $supplement->id,
            'quantity' => 5,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertCreated();

    assertDatabaseHas('supplement_logs', [
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 5,
    ]);

    // Check stock
    expect($supplement->fresh()->servings_remaining)->toBe(95);
});

test('user cannot create log for others supplement', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $other->id,
        'name' => 'Creatine',
        'servings_remaining' => 100,
        'low_stock_threshold' => 10,
    ]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.supplement-logs.store'), [
            'supplement_id' => $supplement->id,
            'quantity' => 5,
            'consumed_at' => now()->toDateTimeString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id']);
});

test('user can update log', function () {
    $user = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 100,
        'low_stock_threshold' => 10,
    ]);
    $log = SupplementLog::create([
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 5,
        'consumed_at' => now(),
    ]);
    $supplement->decrement('servings_remaining', 5); // Simulate initial consumption

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.supplement-logs.update', $log), [
            'quantity' => 10,
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity', 10);

    assertDatabaseHas('supplement_logs', [
        'id' => $log->id,
        'quantity' => 10,
    ]);

    // Stock should change based on update logic (Diff +5)
    // Original 100. Created log 5 -> 95. Updated log 10 (Diff +5). Stock 95 - 5 = 90.
    expect($supplement->fresh()->servings_remaining)->toBe(90);
});

test('user can delete log and stock increases', function () {
    $user = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 95,
        'low_stock_threshold' => 10,
    ]);
    $log = SupplementLog::create([
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 5,
        'consumed_at' => now(),
    ]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.supplement-logs.destroy', $log))
        ->assertNoContent();

    assertDatabaseMissing('supplement_logs', ['id' => $log->id]);

    // Stock should increase
    expect($supplement->fresh()->servings_remaining)->toBe(100);
});
