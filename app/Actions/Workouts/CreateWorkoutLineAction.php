<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

class CreateWorkoutLineAction
{
    /**
     * Create a new workout line for a workout.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Workout $workout, array $data): WorkoutLine
    {
        $key = CreateSetAction::idempotencyKey($data);

        /**
         * Scoped to the workout, never searched globally. The key arrives in a
         * client-controlled header, and a global lookup would hand back another
         * user's line to anyone replaying their key.
         */
        if ($key !== null) {
            $existing = $workout->workoutLines()->where('idempotency_key', $key)->first();

            if ($existing instanceof WorkoutLine) {
                return $existing;
            }
        }

        $maxOrder = $workout->workoutLines()->max('order');
        $order = $data['order'] ?? (is_null($maxOrder) ? 0 : (int) $maxOrder + 1); // @phpstan-ignore cast.int

        /** @var array<string, mixed> $attributs */
        $attributs = array_merge(Arr::except($data, ['workout_id', 'idempotency_key']), ['order' => $order]);

        /** @var WorkoutLine $line */
        $line = $workout->workoutLines()->make($attributs);
        $line->idempotency_key = $key;

        try {
            $line->save();
        } catch (UniqueConstraintViolationException $e) {
            // Two replays of the same attempt raced and the index settled it.
            $winner = $workout->workoutLines()->where('idempotency_key', $key)->first();

            if (! $winner instanceof WorkoutLine) {
                throw $e;
            }

            return $winner;
        }

        return $line;
    }
}
