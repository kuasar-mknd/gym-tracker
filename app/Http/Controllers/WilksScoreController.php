<?php

namespace App\Http\Controllers;

use App\Actions\Tools\CreateWilksScoreAction;
use App\Http\Requests\StoreWilksScoreRequest;
use App\Models\WilksScore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WilksScoreController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        // Check if user is authenticated (should be covered by route middleware, but good practice)
        $user = Auth::user();

        // Fetch history
        $history = $user->wilksScores()
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Tools/WilksCalculator', [
            'history' => $history,
        ]);
    }

    public function store(StoreWilksScoreRequest $request, CreateWilksScoreAction $createWilksScoreAction)
    {
        $validated = $request->validated();

        $createWilksScoreAction->execute($request->user(), $validated);

        return redirect()->back();
    }

    public function destroy(WilksScore $wilksScore)
    {
        // Manual ownership check since we don't have a dedicated Policy for this simple tool
        if ($wilksScore->user_id !== Auth::id()) {
            abort(403);
        }

        $wilksScore->delete();

        return redirect()->back();
    }
}
