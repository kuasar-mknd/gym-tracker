<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La série de jours ne compte que les séances faites, terminées avec une série
 * cochée (#2171) : chaque cas les crée par `Workout::factory()->faite()`, dont
 * la série cochée, écrite après la fin de la séance, la fait compter. Ces cas
 * créaient des séances nues, démarrées ou terminées sans rien, et les
 * comptaient.
 */
class StreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_streak_increments_on_consecutive_days(): void
    {
        $user = User::factory()->create();

        // Une séance démarrée, sans série, ne compte pas.
        $user->workouts()->create(['started_at' => Carbon::parse('2023-01-01 08:00:00')]);
        $this->assertEquals(0, $user->refresh()->current_streak);

        // Day 1
        $this->seanceFaite($user, '2023-01-01 12:00:00');

        $this->assertEquals(1, $user->refresh()->current_streak);
        $this->assertEquals(1, $user->refresh()->longest_streak);

        // Day 2
        $this->seanceFaite($user, '2023-01-02 12:00:00');

        $this->assertEquals(2, $user->refresh()->current_streak);
        $this->assertEquals(2, $user->refresh()->longest_streak);
    }

    public function test_streak_does_not_increment_twice_same_day(): void
    {
        $user = User::factory()->create();

        $this->seanceFaite($user, '2023-01-01 10:00:00');

        $this->assertEquals(1, $user->refresh()->current_streak);

        // Later same day
        $this->seanceFaite($user, '2023-01-01 18:00:00');

        $this->assertEquals(1, $user->refresh()->current_streak);
    }

    public function test_streak_resets_after_missed_day(): void
    {
        $user = User::factory()->create();

        // Day 1
        $this->seanceFaite($user, '2023-01-01 12:00:00');
        $this->assertEquals(1, $user->refresh()->current_streak);

        // Skip Day 2, workout on Day 3
        $this->seanceFaite($user, '2023-01-03 12:00:00');

        // Should reset to 1
        $this->assertEquals(1, $user->refresh()->current_streak);
        // Longest streak remains 1
        $this->assertEquals(1, $user->refresh()->longest_streak);
    }

    public function test_longest_streak_persists(): void
    {
        $user = User::factory()->create();

        // 3 days streak
        $this->seanceFaite($user, '2023-01-01 00:00:00');
        $this->seanceFaite($user, '2023-01-02 00:00:00');
        $this->seanceFaite($user, '2023-01-03 00:00:00');

        $this->assertEquals(3, $user->refresh()->current_streak);
        $this->assertEquals(3, $user->refresh()->longest_streak);

        // Break streak (Day 5)
        $this->seanceFaite($user, '2023-01-05 00:00:00');

        $this->assertEquals(1, $user->refresh()->current_streak);
        $this->assertEquals(3, $user->refresh()->longest_streak);
    }

    /**
     * Une séance faite qui commence à cet instant, écrite par les modèles.
     */
    private function seanceFaite(User $user, string $debut): void
    {
        Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => Carbon::parse($debut)]);
    }
}
