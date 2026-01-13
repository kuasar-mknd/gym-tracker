<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use App\Models\Workout;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkoutsController extends Controller
{
    public function index()
    {
        return Inertia::render('Workouts/Index', [
            'workouts' => Workout::with(['workoutLines.exercise', 'workoutLines.sets'])
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
            'exercises' => Exercise::all(),
        ]);
    }

    public function store(Request $request)
    {
        // Pour le moment on veut juste voir si ça marche
        $workout = Workout::create([
            'user_id' => auth()->id(),
            'started_at' => now(),
            'name' => 'Séance du '.now()->format('d/m/Y'),
        ]);

        return back();
    }
}
