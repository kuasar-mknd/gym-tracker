<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Guest', function (): void {
    test('cannot list fasts', function (): void {
        getJson(route('api.v1.fasts.index'))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their fasts', function (): void {
            Fast::factory()->count(3)->create(['user_id' => $this->user->id]);

            $response = getJson(route('api.v1.fasts.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'start_time', 'target_duration_minutes', 'type', 'status'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('user cannot see others fasts', function (): void {
            $otherUser = User::factory()->create();
            Fast::factory()->create(['user_id' => $otherUser->id]);

            $response = getJson(route('api.v1.fasts.index'));

            $response->assertOk()
                ->assertJsonCount(0, 'data');
        });
    });

    describe('Store', function (): void {
        test('user can create a fast', function (): void {
            $data = [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => 16 * 60,
                'type' => '16:8',
            ];

            $response = postJson(route('api.v1.fasts.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.type', '16:8')
                ->assertJsonPath('data.target_duration_minutes', 16 * 60)
                ->assertJsonPath('data.status', 'active'); // Default status

            assertDatabaseHas('fasts', [
                'user_id' => $this->user->id,
                'type' => '16:8',
            ]);
        });

        test('user cannot create a fast if one is already active', function (): void {
            Fast::factory()->create(['user_id' => $this->user->id, 'status' => 'active']);

            $data = [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => 16 * 60,
                'type' => '16:8',
            ];

            postJson(route('api.v1.fasts.store'), $data)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['message']);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.fasts.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);
        });
    });

    describe('Show', function (): void {
        test('user can view their fast', function (): void {
            $fast = Fast::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.fasts.show', $fast))
                ->assertOk()
                ->assertJsonPath('data.id', $fast->id);
        });

        test('user cannot view others fast', function (): void {
            $otherUser = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.fasts.show', $fast))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('user can update their fast', function (): void {
            $fast = Fast::factory()->create(['user_id' => $this->user->id, 'status' => 'active']);

            putJson(route('api.v1.fasts.update', $fast), ['status' => 'completed'])
                ->assertOk()
                ->assertJsonPath('data.status', 'completed');

            assertDatabaseHas('fasts', ['id' => $fast->id, 'status' => 'completed']);
        });

        test('user can update fast details', function (): void {
            $fast = Fast::factory()->create(['user_id' => $this->user->id, 'target_duration_minutes' => 60]);

            putJson(route('api.v1.fasts.update', $fast), ['target_duration_minutes' => 90])
                ->assertOk()
                ->assertJsonPath('data.target_duration_minutes', 90);

            assertDatabaseHas('fasts', ['id' => $fast->id, 'target_duration_minutes' => 90]);
        });

        test('user cannot update others fast', function (): void {
            $otherUser = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.fasts.update', $fast), ['status' => 'completed'])
                ->assertForbidden();
        });

        test('validation: status must be valid', function (): void {
            $fast = Fast::factory()->create(['user_id' => $this->user->id]);

            putJson(route('api.v1.fasts.update', $fast), ['status' => 'invalid_status'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status']);
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their fast', function (): void {
            $fast = Fast::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.fasts.destroy', $fast))
                ->assertNoContent();

            assertDatabaseMissing('fasts', ['id' => $fast->id]);
        });

        test('user cannot delete others fast', function (): void {
            $otherUser = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.fasts.destroy', $fast))
                ->assertForbidden();

            assertDatabaseHas('fasts', ['id' => $fast->id]);
        });
    });
});
