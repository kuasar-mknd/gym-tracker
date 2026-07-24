<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('authenticated user can view supplements page', function (): void {
    $user = User::factory()->create();

    Supplement::factory(3)->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->get(route('supplements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Supplements/Index')
            ->has('supplements', 3)
            ->has('usageHistory')
        );
});

test('unauthenticated user cannot view supplements page', function (): void {
    get(route('supplements.index'))
        ->assertRedirect(route('login'));
});

test('user can create a supplement', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('supplements.store'), [
            'name' => 'Creatine',
            'brand' => 'Optimum',
            'dosage' => '5g',
            'servings_remaining' => 30,
            'low_stock_threshold' => 5,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément ajouté.');

    $this->assertDatabaseHas('supplements', [
        'user_id' => $user->id,
        'name' => 'Creatine',
        'brand' => 'Optimum',
        'dosage' => '5g',
        'servings_remaining' => 30,
        'low_stock_threshold' => 5,
    ]);
});

test('supplement creation requires validation', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('supplements.store'), [
            // Missing required fields
        ])
        ->assertSessionHasErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('user can update their own supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
    ]);

    actingAs($user)
        ->patch(route('supplements.update', $supplement), [
            'name' => 'New Name',
            'brand' => 'New Brand',
            'dosage' => '10g',
            'servings_remaining' => 25,
            'low_stock_threshold' => 3,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément mis à jour.');

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'name' => 'New Name',
        'brand' => 'New Brand',
        'dosage' => '10g',
        'servings_remaining' => 25,
        'low_stock_threshold' => 3,
    ]);
});

test('supplement update requires validation', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->patch(route('supplements.update', $supplement), [
            // Invalid data types
            'name' => '',
            'servings_remaining' => -5,
            'low_stock_threshold' => -1,
        ])
        ->assertSessionHasErrors(['name', 'servings_remaining', 'low_stock_threshold']);
});

test('user cannot update another users supplement', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user1->id,
        'name' => 'User1s Supplement',
    ]);

    actingAs($user2)
        ->patch(route('supplements.update', $supplement), [
            'name' => 'Updated Name',
            'servings_remaining' => 10,
            'low_stock_threshold' => 5,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('supplements', [
        'id' => $supplement->id,
        'name' => 'Updated Name',
    ]);
});

test('user can delete their own supplement', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('supplements.destroy', $supplement))
        ->assertRedirect()
        ->assertSessionHas('success', 'Complément supprimé.');

    $this->assertModelMissing($supplement);
});

test('user cannot delete another users supplement', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user1->id,
    ]);

    actingAs($user2)
        ->delete(route('supplements.destroy', $supplement))
        ->assertForbidden();

    $this->assertModelExists($supplement);
});

test('user can consume their own supplement', function (): void {
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
        'supplement_id' => $supplement->id,
        'user_id' => $user->id,
        'quantity' => 1,
    ]);
});

test('user cannot consume another users supplement', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user1->id,
        'servings_remaining' => 10,
    ]);

    actingAs($user2)
        ->post(route('supplements.consume', $supplement))
        ->assertForbidden();

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'servings_remaining' => 10,
    ]);

    $this->assertDatabaseMissing('supplement_logs', [
        'supplement_id' => $supplement->id,
        'user_id' => $user2->id,
    ]);
});

test('supplement servings cannot drop below zero when consumed', function (): void {
    $user = User::factory()->create();
    $supplement = Supplement::factory()->create([
        'user_id' => $user->id,
        'servings_remaining' => 0,
    ]);

    actingAs($user)
        ->post(route('supplements.consume', $supplement))
        ->assertRedirect()
        ->assertSessionHas('success', 'Consommation enregistrée.');

    $this->assertDatabaseHas('supplements', [
        'id' => $supplement->id,
        'servings_remaining' => 0,
    ]);

    $this->assertDatabaseHas('supplement_logs', [
        'supplement_id' => $supplement->id,
        'user_id' => $user->id,
        'quantity' => 1,
    ]);
});
