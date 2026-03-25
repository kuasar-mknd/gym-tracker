<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Cache;

it('creates a set and clears volume stats', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => 999, // Should be ignored
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    Cache::spy();

    $action = app(CreateSetAction::class);
    $set = $action->execute($user, $workoutLine, $data);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->workout_line_id)->toBe($workoutLine->id)
        ->and($set->weight)->toBe(100.5)
        ->and($set->reps)->toBe(10)
        ->and($set->is_warmup)->toBeFalse()
        ->and($set->is_completed)->toBeTrue();

    $this->assertDatabaseHas('sets', [
        'id' => $set->id,
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ]);

    Cache::shouldHaveReceived('forget')
        ->with("stats.weekly_volume.{$user->id}")
        ->once();
    Cache::shouldHaveReceived('forget')
        ->with("stats.monthly_volume_comparison.{$user->id}")
        ->once();
});
