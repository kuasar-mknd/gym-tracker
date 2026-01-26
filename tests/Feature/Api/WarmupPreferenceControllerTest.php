<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WarmupPreference;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Authenticated User', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    test('user can list their warmup preferences', function (): void {
        $preference = WarmupPreference::create([
            'user_id' => $this->user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [['percent' => 50, 'reps' => 10, 'label' => 'Warmup']],
        ]);

        $response = getJson(route('api.v1.warmup-preferences.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'id' => $preference->id,
                'bar_weight' => 20,
            ]);
    });

    test('user cannot see other users warmup preferences', function (): void {
        $otherUser = User::factory()->create();
        WarmupPreference::create([
            'user_id' => $otherUser->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = getJson(route('api.v1.warmup-preferences.index'));

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('user can create a warmup preference', function (): void {
        $data = [
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [['percent' => 50, 'reps' => 10, 'label' => 'Warmup']],
        ];

        $response = postJson(route('api.v1.warmup-preferences.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'bar_weight' => 20,
            ]);

        assertDatabaseHas('warmup_preferences', [
            'user_id' => $this->user->id,
            'bar_weight' => 20,
        ]);
    });

    test('user can view their own warmup preference', function (): void {
        $preference = WarmupPreference::create([
            'user_id' => $this->user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [['percent' => 50, 'reps' => 10, 'label' => 'Warmup']],
        ]);

        $response = getJson(route('api.v1.warmup-preferences.show', $preference));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $preference->id,
                'bar_weight' => 20,
            ]);
    });

    test('user cannot view others warmup preference', function (): void {
        $otherUser = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $otherUser->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = getJson(route('api.v1.warmup-preferences.show', $preference));

        $response->assertForbidden();
    });

    test('user can update their warmup preference', function (): void {
        $preference = WarmupPreference::create([
            'user_id' => $this->user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [['percent' => 50, 'reps' => 10, 'label' => 'Warmup']],
        ]);

        $response = putJson(route('api.v1.warmup-preferences.update', $preference), [
            'bar_weight' => 25,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'bar_weight' => 25,
            ]);

        assertDatabaseHas('warmup_preferences', [
            'id' => $preference->id,
            'bar_weight' => 25,
        ]);
    });

    test('user cannot update others warmup preference', function (): void {
        $otherUser = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $otherUser->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = putJson(route('api.v1.warmup-preferences.update', $preference), [
            'bar_weight' => 25,
        ]);

        $response->assertForbidden();
    });

    test('user can delete their warmup preference', function (): void {
        $preference = WarmupPreference::create([
            'user_id' => $this->user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = deleteJson(route('api.v1.warmup-preferences.destroy', $preference));

        $response->assertNoContent();

        assertDatabaseMissing('warmup_preferences', ['id' => $preference->id]);
    });

    test('user cannot delete others warmup preference', function (): void {
        $otherUser = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $otherUser->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);

        $response = deleteJson(route('api.v1.warmup-preferences.destroy', $preference));

        $response->assertForbidden();

        assertDatabaseHas('warmup_preferences', ['id' => $preference->id]);
    });
});

describe('Unauthenticated User', function (): void {
    test('guest cannot list warmup preferences', function (): void {
        $response = getJson(route('api.v1.warmup-preferences.index'));
        $response->assertUnauthorized();
    });

    test('guest cannot create warmup preference', function (): void {
        $response = postJson(route('api.v1.warmup-preferences.store'), []);
        $response->assertUnauthorized();
    });

    test('guest cannot view warmup preference', function (): void {
        $user = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);
        $response = getJson(route('api.v1.warmup-preferences.show', $preference));
        $response->assertUnauthorized();
    });

    test('guest cannot update warmup preference', function (): void {
        $user = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);
        $response = putJson(route('api.v1.warmup-preferences.update', $preference), []);
        $response->assertUnauthorized();
    });

    test('guest cannot delete warmup preference', function (): void {
        $user = User::factory()->create();
        $preference = WarmupPreference::create([
            'user_id' => $user->id,
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ]);
        $response = deleteJson(route('api.v1.warmup-preferences.destroy', $preference));
        $response->assertUnauthorized();
    });
});
