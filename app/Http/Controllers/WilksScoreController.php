<?php

namespace App\Http\Controllers;

use App\Models\WilksScore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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

    public function store(\App\Http\Requests\StoreWilksScoreRequest $request)
    {
        $validated = $request->validated();

        // Calculate Score on Backend
        $bw = $validated['body_weight'];
        $lifted = $validated['lifted_weight'];
        $gender = $validated['gender'];
        $unit = $validated['unit'];

        // Convert to KG for calculation if necessary
        $bwKg = $unit === 'lbs' ? $bw / 2.20462 : $bw;
        $liftedKg = $unit === 'lbs' ? $lifted / 2.20462 : $lifted;

        $score = $this->calculateWilks($bwKg, $liftedKg, $gender);

        $request->user()->wilksScores()->create([
            'body_weight' => $bw,
            'lifted_weight' => $lifted,
            'gender' => $gender,
            'unit' => $unit,
            'score' => $score,
        ]);

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

    private function calculateWilks($bw, $lifted, $gender)
    {
        if ($gender === 'male') {
            $a = -216.0475144;
            $b = 16.2606339;
            $c = -0.002388645;
            $d = -0.00113732;
            $e = 7.01863E-06;
            $f = -1.291E-08;
        } else {
            $a = 594.31747775582;
            $b = -27.23842536447;
            $c = 0.82112226871;
            $d = -0.00930733913;
            $e = 4.731582E-05;
            $f = -9.054E-08;
        }

        $coeff = 500 / ($a + $b * $bw + $c * pow($bw, 2) + $d * pow($bw, 3) + $e * pow($bw, 4) + $f * pow($bw, 5));

        return round($lifted * $coeff, 2);
    }
}
