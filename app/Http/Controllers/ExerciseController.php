<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Models\Exercise;
use App\Services\StatsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ExerciseController extends Controller
{
    use AuthorizesRequests;

    public function show(Exercise $exercise, StatsService $statsService): \Inertia\Response
    {
        $this->authorize('view', $exercise);

        $progress = $statsService->getExercise1RMProgress($this->user(), $exercise->id, 365);

        // Fetch history
        $history = $exercise->workoutLines()
            ->with(['workout' => function ($query) {
                $query->select('id', 'name', 'started_at', 'ended_at');
            }, 'sets'])
            ->whereHas('workout', function ($query) {
                $query->where('user_id', $this->user()->id)
                    ->whereNotNull('ended_at');
            })
            ->get()
            ->sortByDesc('workout.started_at')
            ->values()
            ->map(function ($line) {
                return [
                    'id' => $line->id,
                    'workout_id' => $line->workout->id,
                    'workout_name' => $line->workout->name,
                    'date' => $line->workout->started_at->format('Y-m-d'),
                    'formatted_date' => $line->workout->started_at->format('d/m/Y'),
                    'sets' => $line->sets->map(function ($set) {
                        return [
                            'weight' => $set->weight,
                            'reps' => $set->reps,
                            '1rm' => $set->weight * (1 + $set->reps / 30),
                        ];
                    }),
                    'best_1rm' => $line->sets->max(fn ($set) => $set->weight * (1 + $set->reps / 30)),
                ];
            });

        return Inertia::render('Exercises/Show', [
            'exercise' => $exercise,
            'progress' => $progress,
            'history' => $history,
        ]);
    }

    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', Exercise::class);

        $exercises = Exercise::forUser($this->user()->id)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('Exercises/Index', [
            'exercises' => $exercises,
            'categories' => ['Pectoraux', 'Dos', 'Jambes', 'Épaules', 'Bras', 'Abdominaux', 'Cardio'],
            'types' => [
                ['value' => 'strength', 'label' => 'Force (poids)'],
                ['value' => 'cardio', 'label' => 'Cardio (distance)'],
                ['value' => 'timed', 'label' => 'Temps'],
            ],
        ]);
    }

    public function store(ExerciseStoreRequest $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        // Auth check handled by middleware/request

        $data = $request->validated();
        $exercise = new Exercise($data);
        $exercise->user_id = $this->user()->id;
        $exercise->save();

        // NITRO FIX: Invalidate exercises cache
        Cache::forget('exercises_list_'.$this->user()->id);

        // Return JSON for AJAX requests (from workout page), redirect for regular form submissions
        if ($request->wantsJson() || $request->header('X-Quick-Create')) {
            return response()->json(['exercise' => $exercise], 201);
        }

        return redirect()->back();
    }

    public function update(ExerciseUpdateRequest $request, Exercise $exercise): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $exercise);

        $exercise->update($request->validated());

        // NITRO FIX: Invalidate exercises cache
        Cache::forget('exercises_list_'.Auth::id());

        return redirect()->back();
    }

    public function destroy(Exercise $exercise): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $exercise);

        if ($exercise->workoutLines()->exists()) {
            return redirect()->back()->withErrors([
                'exercise' => 'Cet exercice est utilisé dans une séance et ne peut pas être supprimé.',
            ]);
        }

        $exercise->delete();

        // NITRO FIX: Invalidate exercises cache
        Cache::forget('exercises_list_'.Auth::id());

        return redirect()->back();
    }
}
