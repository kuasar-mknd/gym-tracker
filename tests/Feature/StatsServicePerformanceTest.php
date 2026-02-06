<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use App\Services\StatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_distribution_calculation()
    {
        $user = User::factory()->create();
        $service = new StatsService();

        // Create workouts with different durations
        // < 30 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
        ]);

        // 30-60 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(45),
            'ended_at' => now(),
        ]);

        // 60-90 min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(75),
            'ended_at' => now(),
        ]);

        // 90+ min
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(120),
            'ended_at' => now(),
        ]);

        $distribution = $service->getDurationDistribution($user, 90);

        $this->assertCount(4, $distribution);
        $this->assertEquals(1, collect($distribution)->where('label', '< 30 min')->first()['count']);
        $this->assertEquals(1, collect($distribution)->where('label', '30-60 min')->first()['count']);
        $this->assertEquals(1, collect($distribution)->where('label', '60-90 min')->first()['count']);
        $this->assertEquals(1, collect($distribution)->where('label', '90+ min')->first()['count']);
    }

    public function test_get_duration_history_structure()
    {
        $user = User::factory()->create();
        $service = new StatsService();

        Workout::factory()->create([
            'user_id' => $user->id,
            'name' => 'Session A',
            'started_at' => now()->subDays(1)->setTime(10, 0),
            'ended_at' => now()->subDays(1)->setTime(11, 0), // 60 min
        ]);

        $history = $service->getDurationHistory($user, 10);

        $this->assertCount(1, $history);
        $item = $history[0];
        $this->assertEquals('Session A', $item['name']);
        $this->assertEquals(60, $item['duration']);
        $this->assertArrayHasKey('date', $item);
        $this->assertMatchesRegularExpression('/\d{2}\/\d{2}/', $item['date']);
    }
}
