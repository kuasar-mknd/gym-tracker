<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Injury;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InjuryController extends Controller
{
    use AuthorizesRequests;

    public function index(): \Inertia\Response
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $activeInjuries = $user->injuries()
            ->active()
            ->orderBy('pain_level', 'desc')
            ->orderBy('occurred_at', 'desc')
            ->get();

        $history = $user->injuries()
            ->healed()
            ->orderBy('healed_at', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('Injuries/Index', [
            'activeInjuries' => $activeInjuries,
            'history' => $history,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'body_part' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,recovering,healed'],
            'pain_level' => ['required', 'integer', 'min:1', 'max:10'],
            'occurred_at' => ['required', 'date'],
            'healed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($validated['status'] === 'healed' && empty($validated['healed_at'])) {
            $validated['healed_at'] = now();
        }

        $user->injuries()->create($validated);

        return redirect()->back()->with('success', 'Injury recorded.');
    }

    public function update(Request $request, Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($request->user()->id !== $injury->user_id) {
            abort(403);
        }

        $validated = $request->validate([
            'body_part' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,recovering,healed'],
            'pain_level' => ['required', 'integer', 'min:1', 'max:10'],
            'occurred_at' => ['required', 'date'],
            'healed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validated['status'] === 'healed' && empty($validated['healed_at'])) {
             // If status changed to healed and no date provided, set it to now
            if ($injury->status !== 'healed') {
                $validated['healed_at'] = now();
            }
        }

        $injury->update($validated);

        return redirect()->back()->with('success', 'Injury updated.');
    }

    public function destroy(Request $request, Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($request->user()->id !== $injury->user_id) {
             abort(403);
        }

        $injury->delete();

        return redirect()->back()->with('success', 'Injury deleted.');
    }
}
