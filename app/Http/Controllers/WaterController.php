<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Http\Requests\StoreWaterLogRequest;
use App\Models\WaterLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WaterController extends Controller
{
    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(FetchWaterHistoryAction $fetchWaterHistory, FetchWaterTrackerAction $fetchWaterTracker): Response
    {
        $this->authorize('viewAny', WaterLog::class);

        $user = $this->user();

        $trackerData = $fetchWaterTracker->execute($user);

        return Inertia::render('Tools/WaterTracker', [
            ...$trackerData,
            'history' => $fetchWaterHistory->execute($user),
            'goal' => 2500, // Objectif figé, faute d'un réglage par utilisateur
        ]);
    }

    /**
     * Sans heure soumise, la prise est datée de maintenant : le bouton « + 250
     * ml » n'en envoie pas, et un relevé sans date sortirait de l'historique.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(StoreWaterLogRequest $request): RedirectResponse
    {
        $this->authorize('create', WaterLog::class);

        $data = $request->validated();

        $data['consumed_at'] ??= now();

        $this->user()->waterLogs()->create($data);

        return redirect()->back();
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function destroy(WaterLog $waterLog): RedirectResponse
    {
        $this->authorize('delete', $waterLog);

        $waterLog->delete();

        return redirect()->back();
    }
}
