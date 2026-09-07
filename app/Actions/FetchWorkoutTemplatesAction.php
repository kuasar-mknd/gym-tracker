<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class FetchWorkoutTemplatesAction
{
    /**
     * Les modèles de séance de l'utilisateur, avec le strict nécessaire à
     * l'aperçu de la page d'index : le compte de lignes et les trois premiers
     * exercices.
     *
     * @return Collection<int, WorkoutTemplate>
     */
    public function execute(User $user): Collection
    {
        return WorkoutTemplate::withCount('workoutTemplateLines')
            ->with([
                'workoutTemplateLines' => function (Relation $query): void {
                    /*
                     * `with()` declare `Closure(Relation<*, *, *>)` : la fermeture
                     * doit accepter le type le plus large, un `HasMany` n'est pas
                     * contravariant avec lui. Ici c'est un WorkoutTemplateLine de WorkoutTemplate.
                     */
                    $query->select('id', 'workout_template_id', 'exercise_id')
                        ->orderBy('order')
                        ->limit(3)
                        ->withCount('workoutTemplateSets')
                        ->with('exercise:id,name');
                },
            ])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get();
    }
}
