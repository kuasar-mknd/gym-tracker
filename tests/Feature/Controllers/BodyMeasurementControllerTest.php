<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('BodyMeasurementController', function (): void {
    describe('index', function (): void {
        it('renders the index page for authenticated users', function (): void {
            $user = User::factory()->create();
            BodyMeasurement::factory()->count(3)->create([
                'user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->get(route('body-measurements.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Measurements/Index')
                    ->has('measurements', 3)
                );
        });

        it('redirects unauthenticated users to login', function (): void {
            $this->get(route('body-measurements.index'))
                ->assertRedirect(route('login'));
        });
    });

    describe('store', function (): void {
        it('creates a new body measurement', function (): void {
            $user = User::factory()->create();
            $data = [
                'weight' => 75.5,
                'body_fat' => 15.2,
                'measured_at' => now()->format('Y-m-d'),
                'notes' => 'Feeling good',
            ];

            $this->actingAs($user)
                ->post(route('body-measurements.store'), $data)
                ->assertRedirect();

            $this->assertDatabaseHas('body_measurements', [
                'user_id' => $user->id,
                'weight' => 75.5,
                'body_fat' => 15.2,
                'notes' => 'Feeling good',
            ]);
        });

        it('clears cache after creating a body measurement', function (): void {
            // Need to allow other Cache facade calls
            Cache::spy();

            $user = User::factory()->create();
            $data = [
                'weight' => 75.5,
                'measured_at' => now()->format('Y-m-d'),
            ];

            $this->actingAs($user)
                ->post(route('body-measurements.store'), $data)
                ->assertRedirect();

            Cache::shouldHaveReceived('forget')->atLeast()->once();
        });

        it('requires valid data', function (): void {
            $user = User::factory()->create();

            $this->actingAs($user)
                ->post(route('body-measurements.store'), [])
                ->assertInvalid(['weight', 'measured_at']);
        });

        it('redirects unauthenticated users to login', function (): void {
            $this->post(route('body-measurements.store'), [])
                ->assertRedirect(route('login'));
        });
    });

    describe('destroy', function (): void {
        it('deletes a body measurement', function (): void {
            $user = User::factory()->create();
            $measurement = BodyMeasurement::factory()->create([
                'user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->delete(route('body-measurements.destroy', $measurement))
                ->assertRedirect();

            $this->assertDatabaseMissing('body_measurements', [
                'id' => $measurement->id,
            ]);
        });

        it('clears cache after deleting a body measurement', function (): void {
            Cache::spy();

            $user = User::factory()->create();
            $measurement = BodyMeasurement::factory()->create([
                'user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->delete(route('body-measurements.destroy', $measurement))
                ->assertRedirect();

            Cache::shouldHaveReceived('forget')->atLeast()->once();
        });

        it('prevents deleting another users measurement', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $measurement = BodyMeasurement::factory()->create([
                'user_id' => $otherUser->id,
            ]);

            $this->actingAs($user)
                ->delete(route('body-measurements.destroy', $measurement))
                ->assertForbidden();

            $this->assertDatabaseHas('body_measurements', [
                'id' => $measurement->id,
            ]);
        });

        it('redirects unauthenticated users to login', function (): void {
            $measurement = BodyMeasurement::factory()->create();

            $this->delete(route('body-measurements.destroy', $measurement))
                ->assertRedirect(route('login'));
        });
    });
});
