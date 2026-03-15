<?php

declare(strict_types=1);

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Support\Carbon;

it('fetches water history for the last 7 days including today', function (): void {
    Carbon::setTestNow(Carbon::parse('2024-01-08 12:00:00'));

    $user = User::factory()->create();
    $action = app(FetchWaterHistoryAction::class);

    // Logs for today (2024-01-08)
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::now(), 'amount' => 500]);
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::now()->subHours(2), 'amount' => 250]);

    // Logs for yesterday (2024-01-07)
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::now()->subDay(), 'amount' => 1000]);

    // Logs for 6 days ago (2024-01-02)
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::now()->subDays(6)->startOfDay(), 'amount' => 300]);

    // Logs for 7 days ago (outside the 7-day window, including today) - should not be included
    WaterLog::factory()->create(['user_id' => $user->id, 'consumed_at' => Carbon::now()->subDays(7)->endOfDay(), 'amount' => 500]);

    $history = $action->execute($user);

    expect($history)->toBeArray()
        ->toHaveCount(7);

    // Assert chronological order (oldest to newest): 6 days ago ... today
    expect($history[0]['date'])->toBe('2024-01-02');
    expect($history[0]['total'])->toBe(300.0);

    expect($history[1]['date'])->toBe('2024-01-03');
    expect($history[1]['total'])->toBe(0.0);

    expect($history[2]['date'])->toBe('2024-01-04');
    expect($history[2]['total'])->toBe(0.0);

    expect($history[3]['date'])->toBe('2024-01-05');
    expect($history[3]['total'])->toBe(0.0);

    expect($history[4]['date'])->toBe('2024-01-06');
    expect($history[4]['total'])->toBe(0.0);

    expect($history[5]['date'])->toBe('2024-01-07');
    expect($history[5]['total'])->toBe(1000.0);

    expect($history[6]['date'])->toBe('2024-01-08');
    expect($history[6]['total'])->toBe(750.0); // 500 + 250
});

it('formats day names correctly', function (): void {
    Carbon::setTestNow(Carbon::parse('2024-01-08 12:00:00')); // 2024-01-08 is a Monday

    $user = User::factory()->create();
    $action = app(FetchWaterHistoryAction::class);

    $history = $action->execute($user);

    // Carbon::dayName uses the app locale. We can check either English or app's locale string.
    $expectedTuesday = Carbon::parse('2024-01-02')->dayName;
    $expectedMonday = Carbon::parse('2024-01-08')->dayName;

    expect($history[0]['day_name'])->toBe($expectedTuesday);
    expect($history[6]['day_name'])->toBe($expectedMonday);
});

it('returns zero for days without water logs', function (): void {
    Carbon::setTestNow(Carbon::parse('2024-01-08 12:00:00'));

    $user = User::factory()->create();
    $action = app(FetchWaterHistoryAction::class);

    $history = $action->execute($user);

    foreach ($history as $day) {
        expect($day['total'])->toBe(0.0);
    }
});
