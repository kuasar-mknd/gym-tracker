<?php

declare(strict_types=1);

use App\Models\IntervalTimer;
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
    test('cannot list interval timers', function (): void {
        getJson(route('api.v1.interval-timers.index'))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('user can list their timers', function (): void {
            IntervalTimer::factory()->count(3)->create(['user_id' => $this->user->id]);

            $response = getJson(route('api.v1.interval-timers.index'));

            $response->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'user_id', 'name', 'work_seconds', 'rest_seconds', 'rounds', 'warmup_seconds', 'created_at', 'updated_at'],
                    ],
                ]);
        });
    });

    describe('Store', function (): void {
        test('user can create a timer', function (): void {
            $data = [
                'name' => 'Tabata',
                'work_seconds' => 20,
                'rest_seconds' => 10,
                'rounds' => 8,
                'warmup_seconds' => 60,
            ];

            $response = postJson(route('api.v1.interval-timers.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.name', 'Tabata')
                ->assertJsonPath('data.work_seconds', 20);

            assertDatabaseHas('interval_timers', [
                'user_id' => $this->user->id,
                'name' => 'Tabata',
            ]);
        });

        test('validation: required fields', function (): void {
            postJson(route('api.v1.interval-timers.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'work_seconds', 'rest_seconds', 'rounds']);
        });
    });

    describe('Show', function (): void {
        test('user can view their timer', function (): void {
            $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id]);

            getJson(route('api.v1.interval-timers.show', $timer))
                ->assertOk()
                ->assertJsonPath('data.id', $timer->id);
        });

        test('user cannot view others timer', function (): void {
            $otherUser = User::factory()->create();
            $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

            getJson(route('api.v1.interval-timers.show', $timer))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('user can update their timer', function (): void {
            $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id, 'name' => 'Old Name']);

            putJson(route('api.v1.interval-timers.update', $timer), [
                'name' => 'New Name',
                'work_seconds' => 30,
                'rest_seconds' => 30,
                'rounds' => 3,
                'warmup_seconds' => 0,
            ])
                ->assertOk()
                ->assertJsonPath('data.name', 'New Name');

            assertDatabaseHas('interval_timers', ['id' => $timer->id, 'name' => 'New Name']);
        });

        test('user cannot update others timer', function (): void {
            $otherUser = User::factory()->create();
            $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

            putJson(route('api.v1.interval-timers.update', $timer), [
                'name' => 'Hacked',
                'work_seconds' => 30,
                'rest_seconds' => 30,
                'rounds' => 3,
                'warmup_seconds' => 0,
            ])
                ->assertForbidden();
        });
    });

    describe('Destroy', function (): void {
        test('user can delete their timer', function (): void {
            $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id]);

            deleteJson(route('api.v1.interval-timers.destroy', $timer))
                ->assertNoContent();

            assertDatabaseMissing('interval_timers', ['id' => $timer->id]);
        });

        test('user cannot delete others timer', function (): void {
            $otherUser = User::factory()->create();
            $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

            deleteJson(route('api.v1.interval-timers.destroy', $timer))
                ->assertForbidden();

            assertDatabaseHas('interval_timers', ['id' => $timer->id]);
        });
    });
});
