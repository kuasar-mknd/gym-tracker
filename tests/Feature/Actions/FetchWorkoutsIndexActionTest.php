<?php

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;

it('calculates volume history correctly', function () {
    $user = User::factory()->create();
    $action = app(FetchWorkoutsIndexAction::class);

    // Workout 1: 125 kg total volume
    // 10 * 10 = 100
    // 5 * 5 = 25
    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
        'ended_at' => Carbon::now()->subDays(2)->addHour(),
        'name' => 'Workout 1',
    ]);
    $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id]);
    Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 10, 'reps' => 10]);
    Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 5, 'reps' => 5]);

    // Workout 2: 0 volume (empty)
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(1),
        'ended_at' => Carbon::now()->subDays(1)->addHour(),
        'name' => 'Workout 2',
    ]);

    // Workout 3: Ongoing (should be excluded from volume history per current logic checks 'ended_at'?)
    // Checking code: ->whereNotNull('ended_at')
    $workout3 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
        'ended_at' => null,
        'name' => 'Workout 3',
    ]);

    // Create a set for ongoing workout to make sure it's NOT included
    $line3 = WorkoutLine::factory()->create(['workout_id' => $workout3->id]);
    Set::factory()->create(['workout_line_id' => $line3->id, 'weight' => 100, 'reps' => 1]);

    $result = $action->execute($user);
    $volumeHistory = $result['volumeHistory'];

    expect($volumeHistory)->toHaveCount(2); // Workout 1 and 2

    // volumeHistory is ordered by date (oldest first? or latest first? Logic says ->reverse() at end)
    // Original code: latest('started_at') -> get() -> map -> reverse() -> values()
    // So it should be chronological (oldest to newest).

    // Workout 1 (Oldest)
    expect($volumeHistory[0]['name'])->toBe('Workout 1');
    expect($volumeHistory[0]['volume'])->toBe(125.0); // 10*10 + 5*5

    // Workout 2 (Newest)
    expect($volumeHistory[1]['name'])->toBe('Workout 2');
    expect($volumeHistory[1]['volume'])->toEqual(0); // or 0
});
