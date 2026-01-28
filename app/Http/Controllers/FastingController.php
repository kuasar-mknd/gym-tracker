<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFastingLogRequest;
use App\Http\Requests\UpdateFastingLogRequest;
use App\Models\FastingLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FastingController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->user();

        $activeFast = $user->fastingLogs()
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        $history = $user->fastingLogs()
            ->whereNotNull('end_time')
            ->orderByDesc('end_time')
            ->limit(10)
            ->get();

        return Inertia::render('Tools/FastingTracker', [
            'activeFast' => $activeFast,
            'history' => $history,
        ]);
    }

    public function store(StoreFastingLogRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->user();

        // Check if there is already an active fast
        $activeFast = $user->fastingLogs()->whereNull('end_time')->exists();
        if ($activeFast) {
            return redirect()->back()->with('error', 'Vous avez déjà un jeûne en cours.');
        }

        $user->fastingLogs()->create(array_merge(
            $request->validated(),
            ['start_time' => $request->validated('start_time') ?? now()]
        ));

        return redirect()->back()->with('success', 'Jeûne commencé !');
    }

    public function update(UpdateFastingLogRequest $request, FastingLog $fasting): RedirectResponse
    {
        if ($fasting->user_id !== $this->user()->id) {
            abort(403);
        }

        $data = $request->validated();

        $startTime = isset($data['start_time']) ? \Carbon\Carbon::parse($data['start_time']) : $fasting->start_time;
        $endTime = isset($data['end_time']) ? \Carbon\Carbon::parse($data['end_time']) : $fasting->end_time;

        if ($endTime && $startTime->gt($endTime)) {
            return redirect()->back()->with('error', 'La fin du jeûne ne peut pas être antérieure au début.');
        }

        $fasting->update($data);

        return redirect()->back()->with('success', 'Jeûne mis à jour.');
    }

    public function destroy(FastingLog $fasting): RedirectResponse
    {
        if ($fasting->user_id !== $this->user()->id) {
            abort(403);
        }

        $fasting->delete();

        return redirect()->back()->with('success', 'Entrée supprimée.');
    }
}
