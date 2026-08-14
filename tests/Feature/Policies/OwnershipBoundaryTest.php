<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\DailyJournal;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\IntervalTimer;
use App\Models\MacroCalculation;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Plate;
use App\Models\Set;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use App\Models\WarmupPreference;
use App\Models\WaterLog;
use App\Models\WilksScore;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use App\Policies\BodyMeasurementPolicy;
use App\Policies\BodyPartMeasurementPolicy;
use App\Policies\DailyJournalPolicy;
use App\Policies\FastPolicy;
use App\Policies\GoalPolicy;
use App\Policies\HabitLogPolicy;
use App\Policies\HabitPolicy;
use App\Policies\IntervalTimerPolicy;
use App\Policies\MacroCalculationPolicy;
use App\Policies\NotificationPreferencePolicy;
use App\Policies\PersonalRecordPolicy;
use App\Policies\PlatePolicy;
use App\Policies\SetPolicy;
use App\Policies\SupplementLogPolicy;
use App\Policies\SupplementPolicy;
use App\Policies\WarmupPreferencePolicy;
use App\Policies\WaterLogPolicy;
use App\Policies\WilksScorePolicy;
use App\Policies\WorkoutLinePolicy;
use App\Policies\WorkoutPolicy;
use App\Policies\WorkoutTemplateLinePolicy;
use App\Policies\WorkoutTemplatePolicy;
use App\Policies\WorkoutTemplateSetPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * Every record-scoped policy whose whole job is "this row belongs to this user",
 * with a recipe that builds one such row for a given owner.
 *
 * Recipes live in a function rather than in the dataset itself so the dataset
 * stays a flat list of names, which is what Pest prints on failure.
 *
 * @return array<string, array{0: class-string, 1: \Closure(User): Model}>
 */
function ownedRecordRecipes(): array
{
    return [
        'body measurement' => [BodyMeasurementPolicy::class, fn (User $owner): Model => BodyMeasurement::factory()->create(['user_id' => $owner->id])],
        'body part measurement' => [BodyPartMeasurementPolicy::class, fn (User $owner): Model => BodyPartMeasurement::factory()->create(['user_id' => $owner->id])],
        'daily journal' => [DailyJournalPolicy::class, fn (User $owner): Model => DailyJournal::factory()->create(['user_id' => $owner->id])],
        'fast' => [FastPolicy::class, fn (User $owner): Model => Fast::factory()->create(['user_id' => $owner->id])],
        'goal' => [GoalPolicy::class, fn (User $owner): Model => Goal::factory()->create(['user_id' => $owner->id])],
        'habit' => [HabitPolicy::class, fn (User $owner): Model => Habit::factory()->create(['user_id' => $owner->id])],
        'habit log' => [HabitLogPolicy::class, fn (User $owner): Model => HabitLog::factory()
            ->for(Habit::factory()->create(['user_id' => $owner->id]))
            ->create()
            ->load('habit')],
        'interval timer' => [IntervalTimerPolicy::class, fn (User $owner): Model => IntervalTimer::factory()->create(['user_id' => $owner->id])],
        'macro calculation' => [MacroCalculationPolicy::class, fn (User $owner): Model => MacroCalculation::factory()->create(['user_id' => $owner->id])],
        'notification preference' => [NotificationPreferencePolicy::class, fn (User $owner): Model => NotificationPreference::factory()->create(['user_id' => $owner->id])],
        'personal record' => [PersonalRecordPolicy::class, fn (User $owner): Model => PersonalRecord::factory()->create(['user_id' => $owner->id])],
        'plate' => [PlatePolicy::class, fn (User $owner): Model => Plate::factory()->create(['user_id' => $owner->id])],
        'set' => [SetPolicy::class, fn (User $owner): Model => Set::factory()
            ->for(WorkoutLine::factory()->for(Workout::factory()->create(['user_id' => $owner->id]))->create())
            ->create()
            ->load('workoutLine.workout')],
        'supplement' => [SupplementPolicy::class, fn (User $owner): Model => Supplement::factory()->create(['user_id' => $owner->id])],
        'supplement log' => [SupplementLogPolicy::class, fn (User $owner): Model => SupplementLog::factory()->create(['user_id' => $owner->id])],
        'warmup preference' => [WarmupPreferencePolicy::class, fn (User $owner): Model => WarmupPreference::factory()->create(['user_id' => $owner->id])],
        'water log' => [WaterLogPolicy::class, fn (User $owner): Model => WaterLog::factory()->create(['user_id' => $owner->id])],
        'wilks score' => [WilksScorePolicy::class, fn (User $owner): Model => WilksScore::factory()->create(['user_id' => $owner->id])],
        'workout' => [WorkoutPolicy::class, fn (User $owner): Model => Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => null])],
        'workout line' => [WorkoutLinePolicy::class, fn (User $owner): Model => WorkoutLine::factory()
            ->for(Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => null]))
            ->create()
            ->load('workout')],
        'workout template' => [WorkoutTemplatePolicy::class, fn (User $owner): Model => WorkoutTemplate::factory()->create(['user_id' => $owner->id])],
        'workout template line' => [WorkoutTemplateLinePolicy::class, fn (User $owner): Model => WorkoutTemplateLine::factory()
            ->for(WorkoutTemplate::factory()->create(['user_id' => $owner->id]))
            ->create()
            ->load('workoutTemplate')],
        'workout template set' => [WorkoutTemplateSetPolicy::class, fn (User $owner): Model => WorkoutTemplateSet::factory()
            ->for(WorkoutTemplateLine::factory()->for(WorkoutTemplate::factory()->create(['user_id' => $owner->id]))->create())
            ->create()
            ->load('workoutTemplateLine.workoutTemplate')],
    ];
}

/**
 * Record-scoped policies deliberately left out of the uniform sweep, each with
 * the reason it cannot obey "owner yes, everyone else no".
 */
const OWNERSHIP_SWEEP_EXCLUSIONS = [
    // Permission driven, over the admin guard: tests/Feature/Policies/AdminPolicyTest.php
    'AdminPolicy',
    // Owner is nullable (a null owner is the shared catalogue): PolicyConditionBoundaryTest.php
    'ExercisePolicy',
    // Read-only by design, writes are always denied: PolicyConditionBoundaryTest.php
    'UserAchievementPolicy',
    // Self service plus admin permission, not row ownership: UserPolicyTest.php
    'UserPolicy',
];

dataset('owned records', array_keys(ownedRecordRecipes()));

it('lets the owner read their own record', function (string $recipe): void {
    [$policyClass, $make] = ownedRecordRecipes()[$recipe];
    $owner = User::factory()->create();
    $record = $make($owner);
    $policy = new $policyClass();

    expect($policy->view($owner, $record))->toBeTrue();
})->with('owned records');

it('hides a record from every user but its owner', function (string $recipe): void {
    [$policyClass, $make] = ownedRecordRecipes()[$recipe];
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $record = $make($owner);
    $policy = new $policyClass();

    expect($intruder->id)->not->toBe($owner->id)
        ->and($policy->view($intruder, $record))->toBeFalse();
})->with('owned records');

it('lets the owner write to their own record', function (string $recipe): void {
    [$policyClass, $make] = ownedRecordRecipes()[$recipe];
    $owner = User::factory()->create();
    $record = $make($owner);
    $policy = new $policyClass();

    expect($policy->update($owner, $record))->toBeTrue()
        ->and($policy->delete($owner, $record))->toBeTrue();
})->with('owned records');

it('stops every user but the owner writing to or deleting a record', function (string $recipe): void {
    [$policyClass, $make] = ownedRecordRecipes()[$recipe];
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $record = $make($owner);
    $policy = new $policyClass();

    expect($policy->update($intruder, $record))->toBeFalse()
        ->and($policy->delete($intruder, $record))->toBeFalse();
})->with('owned records');

/**
 * The sweep above is only worth as much as its list. This walks app/Policies and
 * fails when a record-scoped policy is added without being swept or explicitly
 * excused, so a new owned model cannot slip through uncovered.
 */
it('sweeps every record scoped policy in app/Policies', function (): void {
    $recordScoped = collect(glob(app_path('Policies/*.php')))
        ->map(fn (string $path): string => 'App\\Policies\\'.basename($path, '.php'))
        ->filter(function (string $class): bool {
            if (! method_exists($class, 'view')) {
                return false;
            }

            $parameters = new ReflectionMethod($class, 'view')->getParameters();

            if (count($parameters) !== 2) {
                return false;
            }

            $type = $parameters[1]->getType();

            return $type instanceof ReflectionNamedType && str_starts_with($type->getName(), 'App\\Models\\');
        })
        ->map(fn (string $class): string => class_basename($class))
        ->sort()
        ->values()
        ->all();

    $accountedFor = collect(ownedRecordRecipes())
        ->map(fn (array $recipe): string => class_basename($recipe[0]))
        ->merge(OWNERSHIP_SWEEP_EXCLUSIONS)
        ->sort()
        ->values()
        ->all();

    expect($recordScoped)->not->toBeEmpty()
        ->and($recordScoped)->toBe($accountedFor);
});
