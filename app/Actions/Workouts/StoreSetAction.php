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
     * @param  array<string, mixed>  $validated
     *
     * @throws \Exception
     */
    public function execute(User $user, array $validated): Set
    {
        try {
            /** @var \App\Models\WorkoutLine $workoutLine */
            $workoutLine = WorkoutLine::findOrFail($validated['workout_line_id']);

            Gate::forUser($user)->authorize('create', [Set::class, $workoutLine]);

            return $this->createSetAction->execute($user, $workoutLine, $validated);
        } catch (\Exception $e) {
            Log::error('Failed to create set in API:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'data' => $validated,
            ]);

            throw $e;
        }
    }
}
