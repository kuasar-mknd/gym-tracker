<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkoutsController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Workouts/Index', [
            'workouts' => Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }

    public function show(Workout $workout): \Inertia\Response
    {
        abort_if($workout->user_id !== auth()->id(), 403);

        return Inertia::render('Workouts/Show', [
            'workout' => $workout->load(['workoutLines.exercise', 'workoutLines.sets']),
            'exercises' => Exercise::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $workout = Workout::create([
            'user_id' => auth()->id(),
            'started_at' => now(),
            'name' => 'Séance du '.now()->format('d/m/Y'),
        ]);

        return redirect()->route('workouts.show', $workout);
    }
}
