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
     * @param  array<string, mixed>  $data
     */
    public function execute(Workout $workout, array $data): WorkoutLine
    {
        $key = CreateSetAction::idempotencyKey($data);

        /**
         * La recherche est bornée à la séance, jamais globale. La clef arrive
         * dans un en-tête que le client maîtrise, et une recherche globale
         * rendrait la ligne d'un autre utilisateur à qui rejouerait sa clef.
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
            // Deux rejeux de la même tentative se sont croisés, l'index a tranché.
            $winner = $workout->workoutLines()->where('idempotency_key', $key)->first();

            if (! $winner instanceof WorkoutLine) {
                throw $e;
            }

            return $winner;
        }

        return $line;
    }
}
