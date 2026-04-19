<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Permission::firstOrCreate(['name' => 'ViewAny:Supplement', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'View:Supplement', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Create:Supplement', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Update:Supplement', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Delete:Supplement', 'guard_name' => 'web']);
});

test('index displays supplements', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('supplements.index'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Supplements/Index')
            ->has('supplements', 1)
            ->where('supplements.0.id', $supplement->id)
            ->where('supplements.0.name', $supplement->name)
        );
});

test('index unauthenticated redirects to login', function (): void {
    get(route('supplements.index'))
        ->assertRedirect(route('login'));
});

test('store creates supplement', function (): void {
    $user = User::factory()->create();

    $payload = [
        'name' => 'Protein Powder',
        'brand' => 'Optimum Nutrition',
        'dosage' => '1 scoop',
        'servings_remaining' => 30,
        'low_stock_threshold' => 5,
    ];

    actingAs($user)
        ->post(route('supplements.store'), $payload)
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément ajouté.');

    $this->assertDatabaseHas('supplements', [
        'user_id' => $user->id,
        'name' => 'Protein Powder',
        'brand' => 'Optimum Nutrition',
        'dosage' => '1 scoop',
        'servings_remaining' => 30,
        'low_stock_threshold' => 5,
    ]);
});

test('store validation errors', function (): void {
    $user = User::factory()->create();

    $payload = [
        'brand' => 'Optimum Nutrition',
    ];

    actingAs($user)
        ->postJson(route('supplements.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('update modifies supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
        'servings_remaining' => 10,
    ]);

    $payload = [
        'name' => 'New Name',
        'brand' => 'New Brand',
        'dosage' => '2 scoops',
        'servings_remaining' => 15,
        'low_stock_threshold' => 3,
    ];

    actingAs($user)
        ->put(route('supplements.update', $supplement), $payload)
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément mis à jour.');

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'name' => 'New Name',
        'brand' => 'New Brand',
        'dosage' => '2 scoops',
        'servings_remaining' => 15,
        'low_stock_threshold' => 3,
    ]);
});

test('update validation errors', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
    ]);

    $payload = [
        'name' => '', // invalid empty
        'servings_remaining' => -5, // invalid min
    ];

    actingAs($user)
        ->putJson(route('supplements.update', $supplement), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('update authorization error', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $supplement = Supplement::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $payload = [
        'name' => 'New Name',
        'servings_remaining' => 15,
        'low_stock_threshold' => 3,
    ];

    actingAs($user)
        ->put(route('supplements.update', $supplement), $payload)
        ->assertStatus(403);
});

test('destroy deletes supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('supplements.destroy', $supplement))
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément supprimé.');

    $this->assertDatabaseMissing('supplements', [
        'id' => $supplement->id,
    ]);
});

test('destroy authorization error', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $supplement = Supplement::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    actingAs($user)
        ->delete(route('supplements.destroy', $supplement))
        ->assertStatus(403);

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
    ]);
});

test('consume records log and decrements inventory', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'servings_remaining' => 10,
    ]);

    actingAs($user)
        ->post(route('supplements.consume', $supplement))
        ->assertRedirect()
        ->assertSessionHas('success', 'Consommation enregistrée.');

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'servings_remaining' => 9,
    ]);

    $this->assertDatabaseHas('supplement_logs', [
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => 1,
    ]);
});

test('consume authorization error', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $supplement = Supplement::factory()->create([
        'user_id' => $otherUser->id,
        'servings_remaining' => 10,
    ]);

    actingAs($user)
        ->post(route('supplements.consume', $supplement))
        ->assertStatus(403);

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'servings_remaining' => 10,
    ]);

    $this->assertDatabaseMissing('supplement_logs', [
        'supplement_id' => $supplement->id,
    ]);
});
