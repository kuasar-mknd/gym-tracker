<?php

declare(strict_types=1);

use App\Models\IntervalTimer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

describe('IntervalTimerController', function (): void {
    describe('index', function (): void {
        it('renders the index page for authenticated users with timers', function (): void {
            $user = User::factory()->create();
            IntervalTimer::factory()->count(3)->create([
                'user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->get(route('tools.interval-timer.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Tools/IntervalTimer')
                    ->has('timers', 3)
                );
        });

        it('redirects unauthenticated users to login', function (): void {
            $this->get(route('tools.interval-timer.index'))
                ->assertRedirect(route('login'));
        });
    });

    describe('store', function (): void {
        it('creates a new interval timer successfully', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => 'HIIT Session',
                'work_seconds' => 45,
                'rest_seconds' => 15,
                'rounds' => 5,
                'warmup_seconds' => 60,
            ];

            $this->actingAs($user)
                ->post(route('tools.interval-timer.store'), $data)
                ->assertRedirect(route('tools.interval-timer.index'))
                ->assertSessionHas('success', 'Timer created successfully.');

            $this->assertDatabaseHas('interval_timers', [
                'user_id' => $user->id,
                'name' => 'HIIT Session',
                'work_seconds' => 45,
                'rest_seconds' => 15,
                'rounds' => 5,
                'warmup_seconds' => 60,
            ]);
        });

        it('returns validation errors for invalid data', function (): void {
            $user = User::factory()->create();

            $data = [
                'name' => '', // required
                'work_seconds' => 0, // min 1
                'rest_seconds' => -5, // min 0
                'rounds' => 0, // min 1
            ];

            $this->actingAs($user)
                ->post(route('tools.interval-timer.store'), $data)
                ->assertInvalid(['name', 'work_seconds', 'rest_seconds', 'rounds']);
        });

        it('redirects unauthenticated users to login', function (): void {
            $this->post(route('tools.interval-timer.store'), [])
                ->assertRedirect(route('login'));
        });
    });

    describe('update', function (): void {
        it('updates an existing interval timer', function (): void {
            $user = User::factory()->create();
            $timer = IntervalTimer::factory()->create([
                'user_id' => $user->id,
                'name' => 'Old Name',
                'work_seconds' => 30,
            ]);

            $data = [
                'name' => 'Updated Name',
                'work_seconds' => 60,
                'rest_seconds' => 20,
                'rounds' => 4,
                'warmup_seconds' => 120,
            ];

            $this->actingAs($user)
                ->patch(route('tools.interval-timer.update', $timer), $data)
                ->assertRedirect(route('tools.interval-timer.index'))
                ->assertSessionHas('success', 'Timer updated successfully.');

            $this->assertDatabaseHas('interval_timers', [
                'id' => $timer->id,
                'name' => 'Updated Name',
                'work_seconds' => 60,
            ]);
        });

        it('returns validation errors for invalid update data', function (): void {
            $user = User::factory()->create();
            $timer = IntervalTimer::factory()->create(['user_id' => $user->id]);

            $data = [
                'name' => '',
                'work_seconds' => 0,
            ];

            $this->actingAs($user)
                ->patch(route('tools.interval-timer.update', $timer), $data)
                ->assertInvalid(['name', 'work_seconds']);
        });

        it('prevents updating another users interval timer', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $timer = IntervalTimer::factory()->create([
                'user_id' => $otherUser->id,
                'name' => 'Other Users Timer',
            ]);

            $data = [
                'name' => 'Hacked Name',
                'work_seconds' => 30,
                'rest_seconds' => 10,
                'rounds' => 3,
            ];

            $this->actingAs($user)
                ->patch(route('tools.interval-timer.update', $timer), $data)
                ->assertForbidden();

            $this->assertDatabaseHas('interval_timers', [
                'id' => $timer->id,
                'name' => 'Other Users Timer',
            ]);
        });

        it('redirects unauthenticated users to login', function (): void {
            $timer = IntervalTimer::factory()->create();

            $this->patch(route('tools.interval-timer.update', $timer), [])
                ->assertRedirect(route('login'));
        });
    });

    describe('destroy', function (): void {
        it('deletes an interval timer', function (): void {
            $user = User::factory()->create();
            $timer = IntervalTimer::factory()->create([
                'user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->delete(route('tools.interval-timer.destroy', $timer))
                ->assertRedirect(route('tools.interval-timer.index'))
                ->assertSessionHas('success', 'Timer deleted successfully.');

            $this->assertDatabaseMissing('interval_timers', [
                'id' => $timer->id,
            ]);
        });

        it('prevents deleting another users interval timer', function (): void {
            $user = User::factory()->create();
            $otherUser = User::factory()->create();
            $timer = IntervalTimer::factory()->create([
                'user_id' => $otherUser->id,
            ]);

            $this->actingAs($user)
                ->delete(route('tools.interval-timer.destroy', $timer))
                ->assertForbidden();

            $this->assertDatabaseHas('interval_timers', [
                'id' => $timer->id,
            ]);
        });

        it('redirects unauthenticated users to login', function (): void {
            $timer = IntervalTimer::factory()->create();

            $this->delete(route('tools.interval-timer.destroy', $timer))
                ->assertRedirect(route('login'));
        });
    });
});
