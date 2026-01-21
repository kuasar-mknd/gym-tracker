<?php

namespace App\Http\Controllers;

use App\Models\WarmupPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarmupController extends Controller
{
    public function index(): \Inertia\Response
    {
        $preference = $this->user()->warmupPreference ?? new WarmupPreference([
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [
                ['percent' => 0, 'reps' => 10, 'label' => 'Barre'],
                ['percent' => 40, 'reps' => 5, 'label' => ''],
                ['percent' => 60, 'reps' => 3, 'label' => ''],
                ['percent' => 80, 'reps' => 2, 'label' => ''],
            ],
        ]);

        return Inertia::render('Tools/WarmupCalculator', [
            'preference' => $preference,
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'bar_weight' => ['required', 'numeric', 'min:0'],
            'rounding_increment' => ['required', 'numeric', 'min:0'],
            'steps' => ['required', 'array'],
            'steps.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'steps.*.reps' => ['required', 'integer', 'min:1'],
            'steps.*.label' => ['nullable', 'string', 'max:50'],
        ]);

        $this->user()->warmupPreference()->updateOrCreate(
            ['user_id' => $this->user()->id],
            $validated
        );

        return redirect()->back()->with('success', 'Préférences de récupération sauvegardées.');
    }
}
