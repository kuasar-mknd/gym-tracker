<?php

declare(strict_types=1);

use App\Models\IntervalTimer;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

describe('IntervalTimerController Index', function (): void {
    it('allows an authenticated user to view their interval timers', function (): void {
        IntervalTimer::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get(route('tools.interval-timer.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tools/IntervalTimer')
                ->has('timers', 3)
            );
    });

    it('does not allow an unauthenticated user to view interval timers', function (): void {
        $response = $this->get(route('tools.interval-timer.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('IntervalTimerController Store', function (): void {
    it('allows an authenticated user to create an interval timer', function (): void {
        $data = [
            'name' => 'Tabata',
            'work_seconds' => 20,
            'rest_seconds' => 10,
            'rounds' => 8,
            'warmup_seconds' => 60,
        ];

        $response = $this->actingAs($this->user)->post(route('tools.interval-timer.store'), $data);

        $response->assertRedirect(route('tools.interval-timer.index'))
            ->assertSessionHas('success', 'Timer created successfully.');

        $this->assertDatabaseHas('interval_timers', [
            'user_id' => $this->user->id,
            'name' => 'Tabata',
            'work_seconds' => 20,
            'rest_seconds' => 10,
            'rounds' => 8,
            'warmup_seconds' => 60,
        ]);
    });

    it('returns validation errors for invalid data', function (): void {
        $data = [
            'name' => '', // required
            'work_seconds' => 0, // min 1
            'rest_seconds' => -1, // min 0
            'rounds' => 0, // min 1
            'warmup_seconds' => -1, // min 0
        ];

        $response = $this->actingAs($this->user)->post(route('tools.interval-timer.store'), $data);

        $response->assertSessionHasErrors(['name', 'work_seconds', 'rest_seconds', 'rounds', 'warmup_seconds']);
    });
});

describe('IntervalTimerController Update', function (): void {
    it('allows an authenticated user to update their interval timer', function (): void {
        $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id]);

        $data = [
            'name' => 'Updated Tabata',
            'work_seconds' => 30,
            'rest_seconds' => 15,
            'rounds' => 5,
            'warmup_seconds' => 30,
        ];

        $response = $this->actingAs($this->user)->patch(route('tools.interval-timer.update', $timer), $data);

        $response->assertRedirect(route('tools.interval-timer.index'))
            ->assertSessionHas('success', 'Timer updated successfully.');

        $this->assertDatabaseHas('interval_timers', [
            'id' => $timer->id,
            'name' => 'Updated Tabata',
            'work_seconds' => 30,
        ]);
    });

    it('returns validation errors for invalid update data', function (): void {
        $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id]);

        $data = [
            'name' => '',
            'work_seconds' => 0,
        ];

        $response = $this->actingAs($this->user)->patch(route('tools.interval-timer.update', $timer), $data);

        $response->assertSessionHasErrors(['name', 'work_seconds']);
    });

    it('returns 403 forbidden when a user tries to update another users interval timer', function (): void {
        $otherUser = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

        $data = [
            'name' => 'Stolen Timer',
            'work_seconds' => 20,
            'rest_seconds' => 10,
            'rounds' => 8,
            'warmup_seconds' => 60,
        ];

        $response = $this->actingAs($this->user)->patch(route('tools.interval-timer.update', $timer), $data);

        $response->assertForbidden();

        $this->assertDatabaseMissing('interval_timers', [
            'id' => $timer->id,
            'name' => 'Stolen Timer',
        ]);
    });
});

describe('IntervalTimerController Destroy', function (): void {
    it('allows an authenticated user to delete their interval timer', function (): void {
        $timer = IntervalTimer::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->delete(route('tools.interval-timer.destroy', $timer));

        $response->assertRedirect(route('tools.interval-timer.index'))
            ->assertSessionHas('success', 'Timer deleted successfully.');

        $this->assertDatabaseMissing('interval_timers', [
            'id' => $timer->id,
        ]);
    });

    it('returns 403 forbidden when a user tries to delete another users interval timer', function (): void {
        $otherUser = User::factory()->create();
        $timer = IntervalTimer::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->delete(route('tools.interval-timer.destroy', $timer));

        $response->assertForbidden();

        $this->assertDatabaseHas('interval_timers', [
            'id' => $timer->id,
        ]);
    });
});
