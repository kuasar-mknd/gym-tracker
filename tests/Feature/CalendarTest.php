<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('calendar.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Calendar/Index')
            ->has('year')
            ->has('month')
            ->has('workouts')
            ->has('journals')
        );
    }

    public function test_calendar_displays_workouts_for_month(): void
    {
        $user = User::factory()->create();

        // Create a workout for this month
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => now()->addHour(),
        ]);

        // Create a workout for next month (should not be visible)
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->addMonth(),
            'ended_at' => now()->addMonth()->addHour(),
        ]);

        $response = $this->actingAs($user)->get(route('calendar.index'));

        $response->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Calendar/Index')
            ->has('workouts', 1)
            ->where('workouts.0.id', $workout->id)
        );
    }

    public function test_calendar_displays_journals_for_month(): void
    {
        $user = User::factory()->create();

        // Create a journal for this month
        $journal = DailyJournal::factory()->create([
            'user_id' => $user->id,
            'date' => now(),
        ]);

        // Create a journal for next month (should not be visible)
        DailyJournal::factory()->create([
            'user_id' => $user->id,
            'date' => now()->addMonth(),
        ]);

        $response = $this->actingAs($user)->get(route('calendar.index'));

        $response->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Calendar/Index')
            ->has('journals', 1)
            ->where('journals.0.id', $journal->id)
        );
    }

    public function test_can_navigate_to_specific_month(): void
    {
        $user = User::factory()->create();

        $year = 2023;
        $month = 5; // May

        $response = $this->actingAs($user)->get(route('calendar.index', ['year' => $year, 'month' => $month]));

        $response->assertInertia(fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Calendar/Index')
            ->where('year', $year)
            ->where('month', $month)
        );
    }
}
