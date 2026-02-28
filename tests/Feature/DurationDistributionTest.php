<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DurationDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_correctly_buckets_workout_durations(): void
    {
        $user = User::factory()->create();
        $service = new StatsService();

        // < 30 min: 20 mins
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
        ]);

        // 30-60 min: 45 mins
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(45),
            'ended_at' => now(),
        ]);

        // 60-90 min: 75 mins
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(75),
            'ended_at' => now(),
        ]);

        // 90+ min: 100 mins
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(100),
            'ended_at' => now(),
        ]);

        $results = $service->getDurationDistribution($user, 30);

        $this->assertCount(4, $results);

        $this->assertEquals('< 30 min', $results[0]['label']);
        $this->assertEquals(1, $results[0]['count']);

        $this->assertEquals('30-60 min', $results[1]['label']);
        $this->assertEquals(1, $results[1]['count']);

        $this->assertEquals('60-90 min', $results[2]['label']);
        $this->assertEquals(1, $results[2]['count']);

        $this->assertEquals('90+ min', $results[3]['label']);
        $this->assertEquals(1, $results[3]['count']);
    }

    public function test_it_filters_by_days(): void
    {
        $user = User::factory()->create();
        $service = new StatsService();

        // Workout within 30 days
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(10),
            'ended_at' => now()->subDays(10)->addMinutes(40),
        ]);

        // Workout outside 30 days
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(40),
            'ended_at' => now()->subDays(40)->addMinutes(40),
        ]);

        $results = $service->getDurationDistribution($user, 30);

        $this->assertEquals('30-60 min', $results[1]['label']);
        $this->assertEquals(1, $results[1]['count']);
    }
}
