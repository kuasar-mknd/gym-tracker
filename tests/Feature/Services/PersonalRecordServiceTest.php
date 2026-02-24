<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use App\Services\PersonalRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = new PersonalRecordService();
    Notification::fake();
});

it('returns early if set is a warmup', function (): void {
    $set = Set::factory()->make(['is_warmup' => true]);

    $this->service->syncSetPRs($set);

    expect(PersonalRecord::count())->toBe(0);
});

it('returns early if set weight is missing', function (): void {
    $set = Set::factory()->make(['weight' => 0, 'reps' => 10]);

    $this->service->syncSetPRs($set);

    expect(PersonalRecord::count())->toBe(0);
});

it('returns early if set reps is missing', function (): void {
    $set = Set::factory()->make(['weight' => 100, 'reps' => 0]);

    $this->service->syncSetPRs($set);

    expect(PersonalRecord::count())->toBe(0);
});

it('creates personal records correctly for a new exercise', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set);

    expect(PersonalRecord::where('user_id', $user->id)->where('exercise_id', $exercise->id)->count())->toBe(3);

    $maxWeight = PersonalRecord::where('type', 'max_weight')->first();
    expect((float) $maxWeight->value)->toBe(100.0);
    expect((float) $maxWeight->secondary_value)->toBe(10.0);

    $max1rm = PersonalRecord::where('type', 'max_1rm')->first();
    // 100 * (1 + 10/30) = 133.333... -> rounded to 133.33 in service
    expect((float) $max1rm->value)->toBe(133.33);
    expect((float) $max1rm->secondary_value)->toBe(100.0);

    $maxVolume = PersonalRecord::where('type', 'max_volume_set')->first();
    expect((float) $maxVolume->value)->toBe(1000.0);
    expect($maxVolume->secondary_value)->toBeNull();
});

it('correctly calculates 1RM for single rep sets', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 1,
    ]);

    $this->service->syncSetPRs($set);

    $max1rm = PersonalRecord::where('type', 'max_1rm')->first();
    expect((float) $max1rm->value)->toBe(100.0);
});

it('updates existing personal records if new value is better', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Initial PR
    PersonalRecord::create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 80.0,
        'achieved_at' => now()->subDay(),
    ]);

    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set);

    $maxWeight = PersonalRecord::where('type', 'max_weight')->first();
    expect((float) $maxWeight->value)->toBe(100.0);
});

it('does not update personal records if new value is not better', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Initial PR
    PersonalRecord::create([
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 120.0,
        'achieved_at' => now()->subDay(),
    ]);

    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set);

    $maxWeight = PersonalRecord::where('type', 'max_weight')->first();
    expect((float) $maxWeight->value)->toBe(120.0);
});

it('sends notification when a PR is achieved and user has notifications enabled', function (): void {
    $user = User::factory()->create();
    $user->notificationPreferences()->create([
        'type' => 'personal_record',
        'is_enabled' => true,
    ]);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set);

    Notification::assertSentTo($user, PersonalRecordAchieved::class);
});

it('does not send notification when notifications are disabled', function (): void {
    $user = User::factory()->create();
    $user->notificationPreferences()->create([
        'type' => 'personal_record',
        'is_enabled' => false,
    ]);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set);

    Notification::assertNotSentTo($user, PersonalRecordAchieved::class);
});

it('uses explicitly provided user if passed', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);
    $set = Set::factory()->create([
        'workout_line_id' => $workoutLine->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    $this->service->syncSetPRs($set, $otherUser);

    expect(PersonalRecord::where('user_id', $otherUser->id)->count())->toBe(3);
    expect(PersonalRecord::where('user_id', $user->id)->count())->toBe(0);
});
