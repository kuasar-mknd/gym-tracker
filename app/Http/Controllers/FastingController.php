<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFastRequest;
use App\Http\Requests\UpdateFastRequest;
use App\Models\Fast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FastingController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();

        $activeFast = $user->fasts()
            ->where('status', 'active')
            ->latest()
            ->first();

        $history = $user->fasts()
            ->where('status', '!=', 'active')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Tools/Fasting/Index', [
            'activeFast' => $activeFast,
            'history' => $history,
        ]);
    }

    public function store(StoreFastRequest $request): RedirectResponse
    {
        $user = $this->user();

        // Check if there is already an active fast
        if ($user->fasts()->where('status', 'active')->exists()) {
            return back()->withErrors(['message' => 'You already have an active fast.']);
        }

        $user->fasts()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return back()->with('success', 'Fast started successfully.');
    }

    public function update(UpdateFastRequest $request, Fast $fast): RedirectResponse
    {
        $fast->update($request->validated());

        return back()->with('success', 'Fast updated successfully.');
    }

    public function destroy(Fast $fast): RedirectResponse
    {
        if ($fast->user_id !== $this->user()->id) {
            abort(403);
        }

        $fast->delete();

        return back()->with('success', 'Fast deleted successfully.');
    }
}
