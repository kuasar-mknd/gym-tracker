<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExerciseStoreRequest;
use App\Http\Requests\ExerciseUpdateRequest;
use App\Models\Exercise;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ExerciseController extends Controller
{
    use AuthorizesRequests;

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
