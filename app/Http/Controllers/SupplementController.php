<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupplementController extends Controller
{
    public function index(): \Inertia\Response
    {
        /** @var User $user */
        $user = $this->user();

        return Inertia::render('Supplements/Index', [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'usageHistory' => $this->getUsageHistory($user),
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

        $this->clearCache($this->user());

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

        $this->clearCache($this->user());

        return redirect()->back()->with('success', 'Complément mis à jour.');
    }

    public function destroy(Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        if ($supplement->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplement->delete();

        $this->clearCache($this->user());

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

        $this->clearCache($this->user());

        return redirect()->back()->with('success', 'Consommation enregistrée.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function getSupplementsWithLatestLog(User $user): \Illuminate\Support\Collection
    {
        return Cache::remember("supplements.list.{$user->id}", 3600, function () use ($user): \Illuminate\Support\Collection {
            /** @var \Illuminate\Support\Collection<int, mixed> $results */
            $results = Supplement::forUser($user->id)
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

            return $results;
        });
    }

    /** @return array<int, array{date: string, count: float}> */
    private function getUsageHistory(User $user): array
    {
        $days = 30;

        return Cache::remember("supplements.history.{$user->id}", 3600, function () use ($user, $days): array {
            $usageHistoryRaw = SupplementLog::where('user_id', $user->id)
                ->where('consumed_at', '>=', now()->subDays($days)->startOfDay())
                ->select(
                    DB::raw('DATE(consumed_at) as date'),
                    DB::raw('SUM(quantity) as count')
                )
                ->groupBy('date')
                ->get()
                ->pluck('count', 'date');

            /** @var \Illuminate\Support\Collection<string, float> $results */
            $results = $usageHistoryRaw;

            return $this->fillUsageHistory($results, $days);
        });
    }

    private function clearCache(User $user): void
    {
        Cache::forget("supplements.list.{$user->id}");
        Cache::forget("supplements.history.{$user->id}");
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $usageHistoryRaw
     * @return array<int, array{date: string, count: float}>
     */
    private function fillUsageHistory(\Illuminate\Support\Collection $usageHistoryRaw, int $days): array
    {
        $history = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $carbonDate = now()->subDays($i);
            $dateKey = $carbonDate->format('Y-m-d');
            $dateString = $carbonDate->format('d/m');

            $rawTotal = $usageHistoryRaw[$dateKey] ?? 0.0;

            $history[] = [
                'date' => $dateString,
                'count' => (float) $rawTotal,
            ];
        }

        return $history;
    }
}
