<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSleepLogRequest;
use App\Models\SleepLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SleepController extends Controller
{
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->user();

        $logs = $user->sleepLogs()
            ->orderByDesc('date')
            ->limit(30)
            ->get();

        $averageDuration = $user->sleepLogs()
            ->where('date', '>=', Carbon::now()->subDays(30))
            ->avg('duration_minutes') ?? 0;

        return Inertia::render('Tools/SleepTracker', [
            'logs' => $logs,
            'history' => $this->getSleepHistory($user),
            'averageDuration' => (int) $averageDuration,
        ]);
    }

    public function store(StoreSleepLogRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->user()->sleepLogs()->create($data);

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

    /** @return array<int, array{date: string, day_name: string, total: float, quality: float}> */
    private function getSleepHistory(User $user): array
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $logs = $user->sleepLogs()
            ->where('date', '>=', $startDate)
            ->get();

        $history = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateString = $date->format('Y-m-d');

            $dayLogs = $logs->filter(function (SleepLog $log) use ($dateString): bool {
                return $log->date->format('Y-m-d') === $dateString;
            });

            $totalDuration = $dayLogs->sum('duration_minutes');
            $avgQuality = $dayLogs->avg('quality') ?? 0;

            $history[] = [
                'date' => $dateString,
                'day_name' => $date->dayName,
                'total' => (float) $totalDuration,
                'quality' => (float) $avgQuality,
            ];
        }

        return $history;
    }
}
