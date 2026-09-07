<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Models\WilksScore;
use Inertia\Inertia;

class WilksScoreController extends Controller
{
    public function index(): \Inertia\Response
    {
        $this->authorize('viewAny', WilksScore::class);

        $user = $this->user();

        $historique = $user->wilksScores()
            // Borné à 100, comme `BodyMeasurementController` et
            // `DailyJournalController` : l'historique complet partait dans la
            // réponse, et il grandit à chaque usage.
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return Inertia::render('Tools/WilksCalculator', [
            'history' => $historique,
        ]);
    }

    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $createWilksScoreAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', WilksScore::class);

        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $donneesValidees */
        $donneesValidees = $request->validated();

        $createWilksScoreAction->execute($this->user(), $donneesValidees);

        return redirect()->back();
    }

    public function destroy(WilksScore $wilksScore): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $wilksScore);

        $wilksScore->delete();

        return redirect()->back();
    }
}
