<?php

namespace App\Http\Controllers;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplementController extends Controller
{
    public function index(): \Inertia\Response
    {
        /** @var User $user */
        $user = $this->user();

        return Inertia::render('Supplements/Index', [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'intakeHistory' => $this->getIntakeHistory($user),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'servings_remaining' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        Supplement::create(array_merge($validated, ['user_id' => $this->user()->id]));

        return redirect()->back()->with('success', 'Complément ajouté.');
    }

    public function update(Request $request, Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        if ($supplement->user_id !== $this->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'servings_remaining' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
        ]);

        $supplement->update($validated);

        return redirect()->back()->with('success', 'Complément mis à jour.');
    }

    public function destroy(Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        if ($supplement->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplement->delete();

        return redirect()->back()->with('success', 'Complément supprimé.');
    }

    public function consume(Request $request, Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        if ($supplement->user_id !== $this->user()->id) {
            abort(403);
        }

        // Create log
        SupplementLog::create([
            'user_id' => $this->user()->id,
            'supplement_id' => $supplement->id,
            'quantity' => 1,
            'consumed_at' => now(),
        ]);

        if ($supplement->servings_remaining > 0) {
            $supplement->decrement('servings_remaining');
        }

        return redirect()->back()->with('success', 'Consommation enregistrée.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, icon: string, current_log: float, unit: string, daily_goal: ?float}>
     */
    protected function getSupplementsWithLatestLog(User $user): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, array{id: int, name: string, icon: string, current_log: float, unit: string, daily_goal: ?float}> $supplements */
        $supplements = Supplement::forUser($user->id)
            ->with(['latestLog'])
            ->get()
            ->map(fn (Supplement $supplement): array => [
                'id' => (int) $supplement->id,
                'name' => (string) $supplement->name,
                'icon' => 'heroicon-o-beaker',
                'current_log' => (float) ($supplement->latestLog->quantity ?? 0.0),
                'unit' => 'servings',
                'daily_goal' => null,
            ]);

        return $supplements;
    }

    /** @return array<int, array{date: string, count: float}> */
    private function getIntakeHistory(User $user): array
    {
        $logs = SupplementLog::where('user_id', $user->id)
            ->where('consumed_at', '>=', now()->subDays(29)->startOfDay())
            ->get()
            ->groupBy(function (SupplementLog $log): string {
                /** @var \Carbon\Carbon $date */
                $date = $log->consumed_at;

                return $date->format('Y-m-d');
            });

        $history = [];
        for ($i = 29; $i >= 0; $i--) {
            $carbonDate = now()->subDays($i);
            $dateKey = $carbonDate->format('Y-m-d');
            $dateString = $carbonDate->format('d/m');

            $rawTotal = isset($logs[$dateKey]) ? $logs[$dateKey]->sum('quantity') : 0.0;

            $history[] = [
                'date' => $dateString,
                'count' => is_numeric($rawTotal) ? (float) $rawTotal : 0.0,
            ];
        }

        return $history;
    }
}
