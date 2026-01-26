<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWarmupPreferenceRequest;
use App\Models\WarmupPreference;
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

    public function update(UpdateWarmupPreferenceRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $this->user()->warmupPreference()->updateOrCreate(
            ['user_id' => $this->user()->id],
            $validated
        );

        return redirect()->back()->with('success', 'Préférences de récupération sauvegardées.');
    }
}
