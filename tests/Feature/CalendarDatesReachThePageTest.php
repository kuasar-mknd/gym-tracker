<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * Three models cast a calendar column with a bare `date`, which serialises
 * through Carbon's toJSON: the value is read in the app timezone and rendered
 * in UTC. Saved on 31 July under Europe/Paris, it left as
 * "2026-07-30T22:00:00.000000Z" — the wrong format and the wrong day.
 *
 * BodyPartMeasurement and DailyJournal already used `date:Y-m-d`. These three
 * had missed it.
 */
it('sends habit log dates in the format the week grid compares against', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    HabitLog::factory()->create([
        'habit_id' => $habit->id,
        'date' => now()->startOfWeek()->format('Y-m-d'),
    ]);

    $props = actingAs($user)->get(route('habits.index'))->viewData('page')['props'];

    $columnDates = collect($props['weekDates'])->pluck('date')->all();
    $logDates = collect($props['habits'])->flatMap(fn (mixed $habit): array => collect(
        (array) data_get($habit, 'logs', [])
    )->pluck('date')->all())->all();

    expect($logDates)->not->toBeEmpty();

    // The grid draws a box as done with `log.date === day.date`. If the two
    // sides do not even share a format, no box can ever be drawn — which is
    // what shipped: every day looked untouched while the counter beside it
    // read "1/7".
    foreach ($logDates as $logDate) {
        expect($logDate)->toBeIn($columnDates);
    }
});

it('marks the day a habit was actually logged, not the day before', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $loggedOn = now()->startOfWeek()->addDays(2)->format('Y-m-d');

    HabitLog::factory()->create([
        'habit_id' => $habit->id,
        'date' => $loggedOn,
    ]);

    $props = actingAs($user)->get(route('habits.index'))->viewData('page')['props'];
    $logDates = collect($props['habits'])->flatMap(fn (mixed $habit): array => collect(
        (array) data_get($habit, 'logs', [])
    )->pluck('date')->all())->all();

    expect($logDates)->toContain($loggedOn);
});

it('dates a weigh-in on the day it was recorded', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'weight' => 75.5,
        'measured_at' => '2026-07-31',
    ]);

    actingAs($user)
        ->get(route('body-measurements.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('measurements.0.measured_at', '2026-07-31'));
});

it('keeps a goal deadline on its own day', function (): void {
    $user = User::factory()->create();
    Goal::factory()->create([
        'user_id' => $user->id,
        'type' => 'frequency',
        'exercise_id' => null,
        'deadline' => '2026-12-31',
    ]);

    actingAs($user)
        ->get(route('goals.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('goals.0.deadline', '2026-12-31'));
});
