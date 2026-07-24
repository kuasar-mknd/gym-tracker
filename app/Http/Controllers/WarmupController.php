<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWarmupPreferenceRequest;
use App\Models\WarmupPreference;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing warmup preferences and rendering the warmup calculator tool.
 *
 * This controller allows users to configure and save their preferred warmup
 * configurations (e.g., bar weight, percentages, reps) for strength training.
 */
class WarmupController extends Controller
{
    /**
     * Display the warmup calculator tool and the user's current warmup preferences.
     *
     * Retrieves the authenticated user's saved warmup preferences. If none exist,
     * initializes a new WarmupPreference instance with sensible default values.
     *
     * @return Response The Inertia response rendering the 'Tools/WarmupCalculator' page.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', WarmupPreference::class);

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

    /**
     * Update or create the authenticated user's warmup preferences.
     *
     * Validates the incoming request and updates the user's existing preferences
     * or creates a new record if one does not already exist.
     *
     * @param  UpdateWarmupPreferenceRequest  $request  The validated HTTP request containing warmup preference data.
     * @return RedirectResponse A redirect response back to the previous page with a success message.
     */
    public function update(UpdateWarmupPreferenceRequest $request): RedirectResponse
    {
        $preference = $this->user()->warmupPreference;

        if ($preference) {
            $this->authorize('update', $preference);
        } else {
            $this->authorize('create', WarmupPreference::class);
        }

        $validated = $request->validated();

        $this->user()->warmupPreference()->updateOrCreate(
            ['user_id' => $this->user()->id],
            $validated
        );

        return redirect()->back()->with('success', 'Préférences de récupération sauvegardées.');
    }
}
