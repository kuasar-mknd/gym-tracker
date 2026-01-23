<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Models\WilksScore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class WilksScoreController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        // Check if user is authenticated (should be covered by route middleware, but good practice)
        $user = $this->user();

        // Fetch history
        $history = $user->wilksScores()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tools/WilksCalculator', [
            'history' => $history,
        ]);
    }

    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $createWilksScoreAction): \Illuminate\Http\RedirectResponse
    {
        /** @var array{body_weight: float, lifted_weight: float, gender: 'male'|'female', unit: 'kg'|'lbs'} $validated */
        $validated = $request->validated();

        $createWilksScoreAction->execute($this->user(), $validated);

        return redirect()->back();
    }

    public function destroy(WilksScore $wilksScore): \Illuminate\Http\RedirectResponse
    {
        // Manual ownership check since we don't have a dedicated Policy for this simple tool
        if ($wilksScore->user_id !== $this->user()->id) {
            abort(403);
        }

        $wilksScore->delete();

        return redirect()->back();
    }
}
