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

describe('Guest', function (): void {
    test('cannot list warmup preferences', function (): void {
        getJson(route('api.v1.warmup-preferences.index'))->assertUnauthorized();
    });

    test('cannot create warmup preference', function (): void {
        postJson(route('api.v1.warmup-preferences.store'), [])->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their warmup preferences', function (): void {
            WarmupPreference::factory()->create(['user_id' => $this->user->id]);

            $response = getJson(route('api.v1.warmup-preferences.index'));

            $response->assertOk()
                ->assertJsonCount(1, 'data');
        });

        test('user cannot see others warmup preferences', function (): void {
            $otherUser = User::factory()->create();
            WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

            $response = getJson(route('api.v1.warmup-preferences.index'));

            $response->assertOk()
                ->assertJsonCount(0, 'data');
        });
    });

    describe('Store', function (): void {
        test('user can create a warmup preference', function (): void {
            $data = [
                'bar_weight' => 20.0,
                'rounding_increment' => 2.5,
                'steps' => [
                    ['percent' => 50, 'reps' => 10, 'label' => 'Empty Bar'],
                ],
            ];

            $response = postJson(route('api.v1.warmup-preferences.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.bar_weight', 20);

            assertDatabaseHas('warmup_preferences', [
                'user_id' => $this->user->id,
                'bar_weight' => 20.0,
            ]);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.warmup-preferences.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['bar_weight', 'rounding_increment', 'steps']);
        });

        test('validation: steps structure', function (): void {
            $data = [
                'bar_weight' => 20.0,
                'rounding_increment' => 2.5,
                'steps' => [
                    ['percent' => 150, 'reps' => 0], // Invalid
                ],
            ];

            postJson(route('api.v1.warmup-preferences.store'), $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['steps.0.percent', 'steps.0.reps']);
        });
    });

    describe('Show', function (): void {
        test('user can view their warmup preference', function (): void {
            $pref = WarmupPreference::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.warmup-preferences.show', $pref))
                ->assertOk()
                ->assertJsonPath('data.id', $pref->id);
        });

        test('user cannot view others warmup preference', function (): void {
            $otherUser = User::factory()->create();
            $pref = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.warmup-preferences.show', $pref))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('user can update their warmup preference', function (): void {
            $pref = WarmupPreference::factory()->create(['user_id' => $this->user->id]);

            $data = [
                'bar_weight' => 25.0,
            ];

            putJson(route('api.v1.warmup-preferences.update', $pref), $data)
                ->assertOk()
                ->assertJsonPath('data.bar_weight', 25);

            assertDatabaseHas('warmup_preferences', [
                'id' => $pref->id,
                'bar_weight' => 25.0,
            ]);
        });

        test('user cannot update others warmup preference', function (): void {
            $otherUser = User::factory()->create();
            $pref = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.warmup-preferences.update', $pref), ['bar_weight' => 30.0])
                ->assertForbidden();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their warmup preference', function (): void {
            $pref = WarmupPreference::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.warmup-preferences.destroy', $pref))
                ->assertNoContent();

            assertDatabaseMissing('warmup_preferences', ['id' => $pref->id]);
        });

        test('user cannot delete others warmup preference', function (): void {
            $otherUser = User::factory()->create();
            $pref = WarmupPreference::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.warmup-preferences.destroy', $pref))
                ->assertForbidden();
        });
    });
});
