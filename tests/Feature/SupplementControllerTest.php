<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

describe('SupplementController', function (): void {

    // Happy Path Tests
    test('authenticated user can view supplements page', function (): void {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('supplements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Supplements/Index')
                ->has('supplements')
                ->has('usageHistory')
            );
    });

    test('authenticated user can create supplement', function (): void {
        $user = User::factory()->create();

        actingAs($user)
            ->post(route('supplements.store'), [
                'name' => 'Creatine Monohydrate',
                'brand' => 'Optimum Nutrition',
                'dosage' => '5g',
                'servings_remaining' => 60,
                'low_stock_threshold' => 10,
            ])
            ->assertRedirect();

        assertDatabaseHas('supplements', [
            'user_id' => $user->id,
            'name' => 'Creatine Monohydrate',
            'brand' => 'Optimum Nutrition',
            'dosage' => '5g',
            'servings_remaining' => 60,
            'low_stock_threshold' => 10,
        ]);
    });

    test('authenticated user can update supplement', function (): void {
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
                'servings_remaining' => 30,
                'low_stock_threshold' => 5,
            ])
            ->assertRedirect();

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'name' => 'New Name',
            'brand' => 'New Brand',
        ]);
    });

    test('authenticated user can delete supplement', function (): void {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $user->id,
        ]);

        actingAs($user)
            ->delete(route('supplements.destroy', $supplement))
            ->assertRedirect();

        assertDatabaseMissing('supplements', [
            'id' => $supplement->id,
        ]);
    });

    test('authenticated user can consume supplement', function (): void {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $user->id,
            'servings_remaining' => 10,
        ]);

        actingAs($user)
            ->post(route('supplements.consume', $supplement))
            ->assertRedirect();

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'servings_remaining' => 9,
        ]);

        assertDatabaseHas('supplement_logs', [
            'user_id' => $user->id,
            'supplement_id' => $supplement->id,
            'quantity' => 1,
        ]);
    });

    // Validation Tests
    test('validation errors for creating supplement', function (): void {
        $user = User::factory()->create();

        actingAs($user)
            ->post(route('supplements.store'), [
                'name' => '', // Required
                'servings_remaining' => 'not-a-number', // Integer
            ])
            ->assertSessionHasErrors(['name', 'servings_remaining']);
    });

    test('validation errors for updating supplement', function (): void {
        $user = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $user->id]);

        actingAs($user)
            ->patch(route('supplements.update', $supplement), [
                'name' => '', // Required
                'servings_remaining' => -5, // Min 0
            ])
            ->assertSessionHasErrors(['name', 'servings_remaining']);
    });

    // Authorization Tests
    test('unauthenticated user cannot access supplement pages', function (): void {
        get(route('supplements.index'))->assertRedirect(route('login'));
        post(route('supplements.store'), [])->assertRedirect(route('login'));
    });

    test('authenticated user cannot update others supplement', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

        actingAs($user)
            ->patch(route('supplements.update', $supplement), [
                'name' => 'Hacker Update',
                'servings_remaining' => 100,
                'low_stock_threshold' => 10,
            ])
            ->assertForbidden();

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'user_id' => $otherUser->id,
            // Ensure original values remain
            'name' => $supplement->name,
        ]);
    });

    test('authenticated user cannot delete others supplement', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

        actingAs($user)
            ->delete(route('supplements.destroy', $supplement))
            ->assertForbidden(); // The policy/controller should return 403

        assertDatabaseHas('supplements', ['id' => $supplement->id]);
    });

    test('authenticated user cannot consume others supplement', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $supplement = Supplement::factory()->create([
            'user_id' => $otherUser->id,
            'servings_remaining' => 10,
        ]);

        actingAs($user)
            ->post(route('supplements.consume', $supplement))
            ->assertForbidden(); // The controller manually aborts 403

        assertDatabaseHas('supplements', [
            'id' => $supplement->id,
            'servings_remaining' => 10,
        ]);

        assertDatabaseMissing('supplement_logs', [
            'user_id' => $user->id,
            'supplement_id' => $supplement->id,
        ]);
    });
});
