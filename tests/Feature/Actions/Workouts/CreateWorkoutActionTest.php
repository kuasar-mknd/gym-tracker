<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

it('names a workout by its date alone, and clears caches', function (): void {
    Carbon::setTestNow('2024-05-15 14:00:00');

    $user = User::factory()->create();

    // On vérifie l'invalidation par l'état du cache, que StatsCacheManager modifie.

    // Seed caches that should be cleared by clearWorkoutRelatedStats & clearWorkoutMetadataStats
    Cache::put(\App\Services\Stats\ClesDeStats::seances($user, 'weekly_volume'), ['some_data'], 600);
    Cache::put(\App\Services\Stats\ClesDeStats::seances($user, 'volume_trend.30'), ['some_data'], 600);
    Cache::put(\App\Services\Stats\ClesDeStats::seances($user, 'volume_history.20'), ['some_data'], 600);
    Cache::put(\App\Services\Stats\ClesDeStats::seances($user, 'duration_history.20'), ['some_data'], 600);

    $action = app(CreateWorkoutAction::class);
    $workout = $action->execute($user);

    expect($workout)->toBeInstanceOf(Workout::class)
        ->and($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('15/05/2024')
        ->and($workout->started_at->toDateTimeString())->toBe('2024-05-15 14:00:00');

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => '15/05/2024',
        'started_at' => '2024-05-15 14:00:00',
    ]);

    // Verify caches are cleared
    expect(Cache::has(\App\Services\Stats\ClesDeStats::seances($user, 'weekly_volume')))->toBeFalse()
        ->and(Cache::has(\App\Services\Stats\ClesDeStats::seances($user, 'volume_trend.30')))->toBeFalse()
        ->and(Cache::has(\App\Services\Stats\ClesDeStats::seances($user, 'volume_history.20')))->toBeFalse()
        ->and(Cache::has(\App\Services\Stats\ClesDeStats::seances($user, 'duration_history.20')))->toBeFalse();
});
