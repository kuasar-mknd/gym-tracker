<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\InjuryStoreRequest;
use App\Http\Requests\InjuryUpdateRequest;
use App\Models\Injury;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class InjuryController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $injuries = $user->injuries()
            ->orderBy('status', 'asc')
            ->orderBy('occurred_at', 'desc')
            ->get();

        return Inertia::render('Injuries/Index', [
            'injuries' => $injuries,
        ]);
    }

    public function store(InjuryStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->injuries()->create($request->validated());

        return redirect()->back()->with('success', 'Injury logged.');
    }

    public function update(InjuryUpdateRequest $request, Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($injury->user_id !== Auth::id()) {
            abort(403);
        }

        $injury->update($request->validated());

        return redirect()->back()->with('success', 'Injury updated.');
    }

    public function destroy(Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($injury->user_id !== Auth::id()) {
            abort(403);
        }

        $injury->delete();

        return redirect()->back()->with('success', 'Injury deleted.');
    }
}
