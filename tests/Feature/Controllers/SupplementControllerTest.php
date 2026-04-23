<?php

declare(strict_types=1);

use App\Models\Supplement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

describe('SupplementController', function (): void {
    describe('index', function (): void {
        it('allows authenticated user to view supplements index', function (): void {
            $user = User::factory()->create();

            actingAs($user)
                ->get(route('supplements.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Supplements/Index')
                    ->has('supplements')
                );
        });

        it('redirects guest to login', function (): void {
            get(route('supplements.index'))
                ->assertRedirect(route('login'));
        });
    });

    describe('store', function (): void {
        it('allows user to store a supplement with valid data', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => 'Whey Protein',
                'brand' => 'Optimum Nutrition',
                'dosage' => '30g',
                'servings_remaining' => 70,
                'low_stock_threshold' => 10,
            ];

            actingAs($user)
                ->post(route('supplements.store'), $data)
                ->assertRedirect();

            $this->assertDatabaseHas('supplements', [
                'user_id' => $user->id,
                'name' => 'Whey Protein',
                'brand' => 'Optimum Nutrition',
                'dosage' => '30g',
                'servings_remaining' => 70,
                'low_stock_threshold' => 10,
            ]);
        });

        it('fails with invalid data', function (): void {
            $user = User::factory()->create();

            actingAs($user)
                ->post(route('supplements.store'), [])
                ->assertSessionHasErrors(['name', 'servings_remaining', 'low_stock_threshold']);
        });
    });

    describe('update', function (): void {
        it('allows user to update their supplement', function (): void {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            $data = [
                'name' => 'Creatine Monohydrate',
                'brand' => 'MyProtein',
                'dosage' => '5g',
                'servings_remaining' => 50,
                'low_stock_threshold' => 15,
            ];

            actingAs($user)
                ->put(route('supplements.update', $supplement), $data)
                ->assertRedirect();

            $this->assertDatabaseHas('supplements', [
                'id' => $supplement->id,
                'name' => 'Creatine Monohydrate',
                'brand' => 'MyProtein',
                'dosage' => '5g',
                'servings_remaining' => 50,
                'low_stock_threshold' => 15,
            ]);
        });

        it('fails to update with invalid data', function (): void {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            actingAs($user)
                ->put(route('supplements.update', $supplement), [
                    'name' => '',
                    'servings_remaining' => -1,
                ])
                ->assertSessionHasErrors(['name', 'servings_remaining', 'low_stock_threshold']);
        });

        it('forbids updating another users supplement', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

            $data = [
                'name' => 'Creatine Monohydrate',
                'brand' => 'MyProtein',
                'dosage' => '5g',
                'servings_remaining' => 50,
                'low_stock_threshold' => 15,
            ];

            actingAs($user)
                ->put(route('supplements.update', $supplement), $data)
                ->assertForbidden();
        });
    });

    describe('destroy', function (): void {
        it('allows user to delete their supplement', function (): void {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $user->id]);

            actingAs($user)
                ->delete(route('supplements.destroy', $supplement))
                ->assertRedirect();

            $this->assertDatabaseMissing('supplements', ['id' => $supplement->id]);
        });

        it('forbids deleting another users supplement', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create(['user_id' => $otherUser->id]);

            actingAs($user)
                ->delete(route('supplements.destroy', $supplement))
                ->assertForbidden();

            $this->assertDatabaseHas('supplements', ['id' => $supplement->id]);
        });
    });

    describe('consume', function (): void {
        it('allows user to consume their supplement', function (): void {
            $user = User::factory()->create();
            $supplement = Supplement::factory()->create([
                'user_id' => $user->id,
                'servings_remaining' => 30,
            ]);

            actingAs($user)
                ->post(route('supplements.consume', $supplement))
                ->assertRedirect();

            $this->assertDatabaseHas('supplements', [
                'id' => $supplement->id,
                'servings_remaining' => 29,
            ]);

            $this->assertDatabaseHas('supplement_logs', [
                'user_id' => $user->id,
                'supplement_id' => $supplement->id,
                'quantity' => 1,
            ]);
        });

        it('forbids consuming another users supplement', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $supplement = Supplement::factory()->create([
                'user_id' => $otherUser->id,
                'servings_remaining' => 30,
            ]);

            actingAs($user)
                ->post(route('supplements.consume', $supplement))
                ->assertForbidden();

            $this->assertDatabaseHas('supplements', [
                'id' => $supplement->id,
                'servings_remaining' => 30,
            ]);
        });
    });
});
