<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWarmupPreferenceRequest;
use App\Models\WarmupPreference;
use Inertia\Inertia;

class WarmupController extends Controller
{
    /**
     * Qui n'a rien réglé reçoit une préférence non enregistrée, garnie de
     * valeurs par défaut : le calculateur montre une montée en charge complète
     * dès la première visite, au lieu d'un formulaire vide.
     */
    public function index(): \Inertia\Response
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
     * L'autorisation dépend de ce qui existe : `update` si la préférence est
     * déjà enregistrée, `create` sinon — un `updateOrCreate` traverse les deux
     * cas, la policy doit donc être choisie avant.
     */
    public function update(UpdateWarmupPreferenceRequest $request): \Illuminate\Http\RedirectResponse
    {
        $preference = $this->user()->warmupPreference;

        if ($preference instanceof WarmupPreference) {
            $this->authorize('update', $preference);
        } else {
            $this->authorize('create', WarmupPreference::class);
        }

        $donneesValidees = $request->validated();

        $this->user()->warmupPreference()->updateOrCreate(
            ['user_id' => $this->user()->id],
            $donneesValidees
        );

        return redirect()->back()->with('success', 'Préférences de récupération sauvegardées.');
    }
}
