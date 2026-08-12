<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WaterLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_consumed_at_between_with_array_of_dates(): void
    {
        $user = User::factory()->create();

        // Create logs on specific dates
        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-01 12:00:00'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-03 12:00:00'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-05 12:00:00'),
        ]);

        // Create log outside range
        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-10 12:00:00'),
        ]);

        $results = WaterLog::consumedAtBetween(['2023-01-01', '2023-01-05'])->get();

        $this->assertCount(3, $results);

        $first = $results->get(0);
        $second = $results->get(1);
        $third = $results->get(2);
        $this->assertNotNull($first, 'Le 1er log de la plage est absent du resultat.');
        $this->assertNotNull($second, 'Le 2e log de la plage est absent du resultat.');
        $this->assertNotNull($third, 'Le 3e log de la plage est absent du resultat.');

        $this->assertEquals('2023-01-01 12:00:00', $first->consumed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-01-03 12:00:00', $second->consumed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-01-05 12:00:00', $third->consumed_at->format('Y-m-d H:i:s'));
    }

    public function test_scope_consumed_at_between_with_comma_separated_string(): void
    {
        $user = User::factory()->create();

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-01 12:00:00'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-05 12:00:00'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-10 12:00:00'),
        ]);

        $results = WaterLog::consumedAtBetween('2023-01-01,2023-01-05')->get();

        $this->assertCount(2, $results);

        $first = $results->get(0);
        $second = $results->get(1);
        $this->assertNotNull($first, 'Le 1er log de la plage est absent du resultat.');
        $this->assertNotNull($second, 'Le 2e log de la plage est absent du resultat.');

        $this->assertEquals('2023-01-01 12:00:00', $first->consumed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-01-05 12:00:00', $second->consumed_at->format('Y-m-d H:i:s'));
    }

    public function test_scope_consumed_at_between_with_single_date(): void
    {
        $user = User::factory()->create();

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-01 00:00:00'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-01 23:59:59'),
        ]);

        WaterLog::factory()->create([
            'user_id' => $user->id,
            'consumed_at' => Carbon::parse('2023-01-02 00:00:00'),
        ]);

        $results = WaterLog::consumedAtBetween('2023-01-01')->get();

        $this->assertCount(2, $results);

        $first = $results->get(0);
        $second = $results->get(1);
        $this->assertNotNull($first, 'Le 1er log de la journee est absent du resultat.');
        $this->assertNotNull($second, 'Le 2e log de la journee est absent du resultat.');

        $this->assertEquals('2023-01-01 00:00:00', $first->consumed_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2023-01-01 23:59:59', $second->consumed_at->format('Y-m-d H:i:s'));
    }
}
