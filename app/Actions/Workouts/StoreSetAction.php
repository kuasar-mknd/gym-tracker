<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class StoreSetAction
{
    public function __construct(
        protected CreateSetAction $createSetAction
    ) {
    }

    /**
     * @param  array<string, mixed>  $donneesValidees
     *
     * @throws \Exception
     */
    public function execute(User $user, array $donneesValidees): Set
    {
        try {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = WorkoutLine::findOrFail($donneesValidees['workout_line_id']);

            Gate::forUser($user)->authorize('create', [Set::class, $workoutLine]);

            return $this->createSetAction->execute($user, $workoutLine, $donneesValidees);
        } catch (\Exception $e) {
            // Ni pile ni charge utile : l'exception est relancée et le gestionnaire
            // la rapporte avec sa pile ; la charge utile n'a rien à faire en journal.
            Log::error('Failed to create set in API:', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            throw $e;
        }
    }
}
