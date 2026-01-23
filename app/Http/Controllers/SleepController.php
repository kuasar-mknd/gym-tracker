<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SleepLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SleepController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->user();

        // Fetch logs for the last 30 days to show in list
        $logs = $user->sleepLogs()
            ->latest('started_at')
            ->limit(30)
            ->get();

        // Calculate history for chart (last 7 days)
        $history = $this->getSleepHistory($user);

        // Check for last night log (first in the sorted list)
        $lastLog = $logs->first();

        return Inertia::render('Tools/SleepTracker', [
            'logs' => $logs,
            'history' => $history,
            'lastLog' => $lastLog,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'quality' => ['nullable', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->user()->sleepLogs()->create([
            'started_at' => Carbon::parse($data['started_at']),
            'ended_at' => Carbon::parse($data['ended_at']),
            'quality' => $data['quality'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->back();
    }

    public function destroy(SleepLog $sleepLog): RedirectResponse
    {
        if ($sleepLog->user_id !== $this->user()->id) {
            abort(403);
        }

        $sleepLog->delete();

        return redirect()->back();
    }

    /** @return array<int, array{date: string, day_name: string, total_hours: float}> */
    private function getSleepHistory(User $user): array
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        // Fetch logs that ended in the last 7 days
        $historyLogs = $user->sleepLogs()
            ->where('ended_at', '>=', $startDate)
            ->get();

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            // Find logs that ended on this date (woke up this day)
            $dayLogs = $historyLogs->filter(function (SleepLog $log) use ($dateString): bool {
                return $log->ended_at->format('Y-m-d') === $dateString;
            });

            $totalSeconds = 0;
            foreach ($dayLogs as $log) {
                // Calculate absolute difference in seconds
                $totalSeconds += $log->ended_at->diffInSeconds($log->started_at);
            }

            $totalHours = round($totalSeconds / 3600, 1);

            $history[] = [
                'date' => $dateString,
                'day_name' => $date->dayName,
                'total_hours' => $totalHours,
            ];
        }

        return $history;
    }
}
