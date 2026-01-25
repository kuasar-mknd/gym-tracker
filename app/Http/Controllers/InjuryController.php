<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreInjuryRequest;
use App\Http\Requests\UpdateInjuryRequest;
use App\Models\Injury;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InjuryController extends Controller
{
    public function index(): Response
    {
        $activeInjuries = Injury::where('user_id', Auth::id())
            ->active()
            ->orderBy('occurred_at', 'desc')
            ->get();

        $injuryHistory = Injury::where('user_id', Auth::id())
            ->where('status', 'healed')
            ->orderBy('healed_at', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Injuries/Index', [
            'activeInjuries' => $activeInjuries,
            'injuryHistory' => $injuryHistory,
        ]);
    }

    public function store(StoreInjuryRequest $request): RedirectResponse
    {
        Auth::user()->injuries()->create($request->validated());

        return redirect()->back()->with('success', 'Injury logged successfully.');
    }

    public function update(UpdateInjuryRequest $request, Injury $injury): RedirectResponse
    {
        if ($injury->user_id !== Auth::id()) {
            abort(403);
        }

        $injury->update($request->validated());

        return redirect()->back()->with('success', 'Injury updated successfully.');
    }

    public function destroy(Injury $injury): RedirectResponse
    {
        if ($injury->user_id !== Auth::id()) {
            abort(403);
        }

        $injury->delete();

        return redirect()->back()->with('success', 'Injury deleted successfully.');
    }
}
