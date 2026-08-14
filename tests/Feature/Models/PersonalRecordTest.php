<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_a_set_creates_personal_records(): void
    {
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'reps' => 10,
            'weight' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 50,
        ]);

        $this->assertDatabaseHas('personal_records', [
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'type' => 'max_1rm',
            'value' => 66.67, // 50 * (1 + 10/30)
        ]);
    }

    public function test_updating_a_set_updates_personal_record(): void
    {
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $set = \App\Models\Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'reps' => 10,
            'weight' => 50,
        ]);

        // Manually trigger service since factory doesn't
        new \App\Services\PersonalRecordService()->syncSetPRs($set, $user);

        $this->patchJson(route('api.v1.sets.update', $set), [
            'reps' => 10,
            'weight' => 60,
        ])->assertOk();

        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 60,
        ]);
    }

    public function test_lower_weight_does_not_overwrite_pr(): void
    {
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        // Create initial PR
        $user->personalRecords()->create([
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 100,
            'achieved_at' => now(),
        ]);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'reps' => 10,
            'weight' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('personal_records', [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 100,
        ]);
    }

    public function test_warmup_set_does_not_create_pr(): void
    {
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $exercise = \App\Models\Exercise::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);

        $this->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $workoutLine->id,
            'reps' => 10,
            'weight' => 50,
            'is_warmup' => true,
        ])->assertCreated();

        $this->assertDatabaseEmpty('personal_records');
    }

    /**
     * PersonalRecordService::shouldSkipSync() écarte trois cas : l'échauffement,
     * le poids absent et les reps absentes. Seul le premier était couvert.
     *
     * Ces tests appellent le service DIRECTEMENT, sans passer par la route : par
     * HTTP, la validation du FormRequest rejette déjà un poids nul, si bien qu'un
     * test passant par là vérifierait la validation et non le garde. Vérifié — il
     * reste vert quand on supprime le garde.
     *
     * Ce que le garde protège : sans lui, processUpdates multiplierait un null,
     * PHP le coercerait en 0, et un record personnel à 0 serait enregistré comme
     * un résultat légitime.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteSets')]
    public function test_an_incomplete_set_does_not_create_a_pr(array $attributes): void
    {
        $this->syncSet($attributes);

        $this->assertDatabaseEmpty('personal_records');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incompleteSets(): array
    {
        return [
            'poids absent' => [['weight' => null, 'reps' => 10]],
            'reps absentes' => [['weight' => 80, 'reps' => null]],
            'poids nul' => [['weight' => 0, 'reps' => 10]],
            'reps nulles' => [['weight' => 80, 'reps' => 0]],
        ];
    }

    /**
     * Le pendant positif : sans lui, les cas ci-dessus passeraient aussi si la
     * création de record était cassée pour tout le monde.
     */
    public function test_a_complete_set_creates_the_three_tracked_records(): void
    {
        $this->syncSet(['weight' => 80, 'reps' => 5]);

        $this->assertDatabaseCount('personal_records', 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncSet(array $attributes): void
    {
        $user = \App\Models\User::factory()->create();
        $workout = \App\Models\Workout::factory()->create(['user_id' => $user->id]);
        $workoutLine = \App\Models\WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => \App\Models\Exercise::factory()->create()->id,
        ]);

        $set = \App\Models\Set::factory()->create([
            'workout_line_id' => $workoutLine->id,
            'is_completed' => true,
        ] + $attributes);

        app(\App\Services\PersonalRecordService::class)->syncSetPRs($set->refresh());
    }
}
