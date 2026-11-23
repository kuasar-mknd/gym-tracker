<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

final class FetchWorkoutsIndexAction
{
    /**
     * La prop de la page des séances : l'historique, paginé par vingt.
     *
     * C'est une fermeture, qu'Inertia ne résout que si sa prop part dans la
     * réponse (#2148, étape 3a).
     *
     * @return array{workouts: \Closure(): LengthAwarePaginator<int, Workout>}
     */
    public function execute(User $user): array
    {
        return [
            'workouts' => fn (): LengthAwarePaginator => $this->seancesDeLUtilisateur($user),
        ];
    }

    /** @return LengthAwarePaginator<int, Workout> */
    private function seancesDeLUtilisateur(User $user): LengthAwarePaginator
    {
        return Workout::with([
            'workoutLines' => function (Relation $query): void {
                /*
                 * `with()` declare `Closure(Relation<*, *, *>)` : la fermeture
                 * doit accepter le type le plus large, un `HasMany` n'est pas
                 * contravariant avec lui. Ici c'est un WorkoutLine de Workout.
                 */
                $query->select(['id', 'workout_id', 'exercise_id', 'order'])
                    ->with('exercise:id,name')
                    ->withCount('sets');
            },
        ])
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->orderByDesc('id')
            ->paginate(20);
    }
}
