<?php

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

test('user can list supplement logs', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::create([
        'user_id' => $user->id,
        'name' => 'Creatine',
        'servings_remaining' => 50,
        'low_stock_threshold' => 5,
    ]);

    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()->subDay()]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()->subDays(2)]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.supplement-logs.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can filter supplement logs by supplement', function (): void {
    $user = User::factory()->create();
    $s1 = Supplement::create(['user_id' => $user->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $s2 = Supplement::create(['user_id' => $user->id, 'name' => 'S2', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);

    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $s1->id, 'quantity' => 1, 'consumed_at' => now()]);
    SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $s2->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.supplement-logs.index', [
            'filter[supplement_id]' => $s1->id,
            'include' => 'supplement',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.supplement.id', $s1->id);
});

test('user can create a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $user->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);

    $data = [
        'supplement_id' => $supplement->id,
        'quantity' => 2,
        'consumed_at' => now()->toDateString(),
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.supplement-logs.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.quantity', 2);

    assertDatabaseHas('supplement_logs', [
        'supplement_id' => $supplement->id,
        'quantity' => 2,
        'user_id' => $user->id,
    ]);
});

test('user can show a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $user->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.supplement-logs.show', $log))
        ->assertOk()
        ->assertJsonPath('data.id', $log->id);
});

test('user can update a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $user->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.supplement-logs.update', $log), [
            'quantity' => 5,
        ])
        ->assertOk()
        ->assertJsonPath('data.quantity', 5);

    assertDatabaseHas('supplement_logs', [
        'id' => $log->id,
        'quantity' => 5,
    ]);
});

test('user can delete a supplement log', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $user->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $user->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.supplement-logs.destroy', $log))
        ->assertNoContent();

    assertDatabaseMissing('supplement_logs', ['id' => $log->id]);
});

test('store requires mandatory fields', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.supplement-logs.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id', 'quantity', 'consumed_at']);
});

test('store validates supplement ownership', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $otherUser->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.supplement-logs.store'), [
            'supplement_id' => $supplement->id,
            'quantity' => 1,
            'consumed_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['supplement_id']);
});

test('user cannot view other user supplement log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $otherUser->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $otherUser->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.supplement-logs.show', $log))
        ->assertForbidden();
});

test('user cannot update other user supplement log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $otherUser->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $otherUser->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.supplement-logs.update', $log), [
            'quantity' => 10,
        ])
        ->assertForbidden();
});

test('user cannot delete other user supplement log', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $supplement = Supplement::create(['user_id' => $otherUser->id, 'name' => 'S1', 'servings_remaining' => 10, 'low_stock_threshold' => 1]);
    $log = SupplementLog::create(['user_id' => $otherUser->id, 'supplement_id' => $supplement->id, 'quantity' => 1, 'consumed_at' => now()]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.supplement-logs.destroy', $log))
        ->assertForbidden();
});
