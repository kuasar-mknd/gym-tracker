<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Eloquent\Collection;

class FetchWorkoutTemplatesAction
{
    /**
     * Fetch all workout templates for the given user, with necessary relations for the index page preview.
     *
     * @return Collection<int, WorkoutTemplate>
     */
    public function execute(User $user): Collection
    {
        // ⚡ Bolt Optimization: Only load the line counts and the first few exercises for preview
        return WorkoutTemplate::withCount('workoutTemplateLines')
            ->with([
                'workoutTemplateLines' => function ($query): void {
                    $query->select('id', 'workout_template_id', 'exercise_id')
                        ->orderBy('order')
                        ->limit(3)
                        ->withCount('workoutTemplateSets')
                        ->with('exercise:id,name');
                },
            ])
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }
}
