<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SupplementStoreRequest;
use App\Http\Requests\SupplementUpdateRequest;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Controller for managing User Supplements.
 *
 * This controller handles the CRUD operations for supplements and tracks their usage.
 * It manages inventory (servings remaining) and logs consumption history.
 */
class SupplementController extends Controller
{
    /**
     * Display a listing of the user's supplements and usage history.
     *
     * Retrieves all supplements for the authenticated user, including their latest
     * consumption log to determine if they've been taken today. Also retrieves
     * the last 30 days of usage history for the visualization chart.
     *
     * @return \Inertia\Response The Inertia response rendering the Supplements/Index page.
     */
    public function index(): \Inertia\Response
    {
        /** @var User $user */
        $user = $this->user();

        return Inertia::render('Supplements/Index', [
            'supplements' => $this->getSupplementsWithLatestLog($user),
            'usageHistory' => $this->getUsageHistory($user),
        ]);
    }

    /**
     * Store a newly created supplement in storage.
     *
     * Validates the input and creates a new supplement record for the authenticated user.
     *
     * @param  \App\Http\Requests\SupplementStoreRequest  $request  The incoming HTTP request containing supplement details.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     */
    public function store(SupplementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        /** @var array{name: string, brand?: string|null, dosage?: string|null, servings_remaining: int, low_stock_threshold: int} $validated */
        $validated = $request->validated();

        Supplement::create(array_merge($validated, ['user_id' => $this->user()->id]));

        return redirect()->back()->with('success', 'Complément ajouté.');
    }

    /**
     * Update the specified supplement in storage.
     *
     * Updates the details of an existing supplement. Ensures the user owns the supplement
     * before applying changes.
     *
     * @param  \App\Http\Requests\SupplementUpdateRequest  $request  The incoming HTTP request with updated details.
     * @param  \App\Models\Supplement  $supplement  The supplement to update.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
    public function update(SupplementUpdateRequest $request, Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        // Auth check is handled by the SupplementUpdateRequest

        /** @var array{name: string, brand?: string|null, dosage?: string|null, servings_remaining: int, low_stock_threshold: int} $validated */
        $validated = $request->validated();

        $supplement->update($validated);

        return redirect()->back()->with('success', 'Complément mis à jour.');
    }

    /**
     * Remove the specified supplement from storage.
     *
     * Permanently deletes a supplement record. Ensures the user owns the supplement.
     *
     * @param  \App\Models\Supplement  $supplement  The supplement to delete.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
    public function destroy(Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        if ($supplement->user_id !== $this->user()->id) {
            abort(403);
        }

        $supplement->delete();

        return redirect()->back()->with('success', 'Complément supprimé.');
    }

    /**
     * Record a consumption event for a supplement.
     *
     * Creates a log entry for the consumption and decrements the inventory count.
     * Prevents the inventory from going below zero.
     *
     * @param  \Illuminate\Http\Request  $request  The HTTP request.
     * @param  \App\Models\Supplement  $supplement  The supplement consumed.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a success message.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException If the user is not authorized (403).
     */
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
     * Retrieve supplements with their latest log status.
     *
     * Fetches all supplements for the user and attaches the latest consumption log.
     * Transforms the data for the frontend, including an icon and unit.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return \Illuminate\Support\Collection<int, mixed> A collection of formatted supplement data.
     */
    protected function getSupplementsWithLatestLog(User $user): \Illuminate\Support\Collection
    {
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
    }

    /**
     * Get the supplement usage history for the last 30 days.
     *
     * Aggregates the total number of supplements consumed per day.
     *
     * @param  \App\Models\User  $user  The authenticated user.
     * @return array<int, array{date: string, count: float}> An array of daily usage counts.
     */
    private function getUsageHistory(User $user): array
    {
        $days = 30;
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
    }

    /**
     * Fill missing dates in the usage history with zero values.
     *
     * Iterates through the last $days to ensure every date has an entry,
     * using 0 for days with no recorded consumption.
     *
     * @param  \Illuminate\Support\Collection<string, float>  $usageHistoryRaw  The raw aggregated data keyed by date.
     * @param  int  $days  The number of days to look back.
     * @return array<int, array{date: string, count: float}> The complete history array with formatted dates.
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
