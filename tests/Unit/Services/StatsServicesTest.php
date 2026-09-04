<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\ExerciseStatsService;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_calculate_volume_trend(): void
    {
        $user = User::factory()->create();
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $trend = app(VolumeStatsService::class)->getVolumeTrend($user);

        $this->assertCount(1, $trend);
        $this->assertEquals(1000, $trend[0]->volume);
    }

    public function test_can_calculate_muscle_distribution(): void
    {
        $user = User::factory()->create();
        $exercise = Exercise::factory()->create(['category' => 'Pectoraux']);
        $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]);

        $dist = app(ExerciseStatsService::class)->getMuscleDistribution($user);

        $this->assertCount(1, $dist);
        $this->assertEquals('Pectoraux', $dist[0]->category);
        $this->assertEquals(1000, $dist[0]->volume);
    }

    public function test_can_calculate_monthly_volume_comparison(): void
    {
        \Carbon\Carbon::setTestNow('2024-03-15 12:00:00');
        $user = User::factory()->create();

        // Current month workout (March)
        $workoutCurrent = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfMonth(),
        ]);
        $lineCurrent = WorkoutLine::factory()->create(['workout_id' => $workoutCurrent->id]);
        Set::factory()->create(['workout_line_id' => $lineCurrent->id, 'weight' => 50, 'reps' => 10]); // 500

        // Previous month workout (February)
        $workoutPrev = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMonth()->startOfMonth(),
        ]);
        $linePrev = WorkoutLine::factory()->create(['workout_id' => $workoutPrev->id]);
        Set::factory()->create(['workout_line_id' => $linePrev->id, 'weight' => 40, 'reps' => 10]); // 400

        $comparison = app(VolumeStatsService::class)->getMonthlyVolumeComparison($user);

        $this->assertEquals(500, $comparison->current_volume);
        $this->assertEquals(400, $comparison->previous_volume);
        $this->assertEquals(100, $comparison->difference);
        $this->assertEquals(25.0, $comparison->percentage);

        \Carbon\Carbon::setTestNow();
    }

    public function test_can_calculate_weekly_volume_trend(): void
    {
        $user = User::factory()->create();

        // Create a workout for today (current week)
        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10,
        ]); // 1000

        $trend = app(VolumeStatsService::class)->getWeeklyVolumeTrend($user);

        $this->assertCount(7, $trend); // Should always return 7 days (Mon-Sun)

        $targetDateStr = now()->startOfWeek()->format('Y-m-d');
        $found = false;

        foreach ($trend as $day) {
            if ($day->date === $targetDateStr) {
                $this->assertEquals(1000, $day->volume);
                $found = true;
            } else {
                $this->assertEquals(0, $day->volume);
            }
        }
        $this->assertTrue($found);
    }

    public function test_can_calculate_weekly_volume_comparison(): void
    {
        $user = User::factory()->create();

        // Current week workout
        $workoutCurrent = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek(),
        ]);
        $lineCurrent = WorkoutLine::factory()->create(['workout_id' => $workoutCurrent->id]);
        Set::factory()->create(['workout_line_id' => $lineCurrent->id, 'weight' => 50, 'reps' => 10]); // 500

        // Previous week workout
        $workoutPrev = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subWeek()->startOfWeek(),
        ]);
        $linePrev = WorkoutLine::factory()->create(['workout_id' => $workoutPrev->id]);
        Set::factory()->create(['workout_line_id' => $linePrev->id, 'weight' => 40, 'reps' => 10]); // 400

        $comparison = app(VolumeStatsService::class)->getWeeklyVolumeComparison($user);

        $this->assertEquals(500, $comparison->current_volume);
        $this->assertEquals(400, $comparison->previous_volume);
        $this->assertEquals(100, $comparison->difference);
        $this->assertEquals(25.0, $comparison->percentage);
    }

    /**
     * Une semaine sans semaine precedente n'a pas de variation, elle n'en a pas
     * une nulle.
     *
     * La formule renvoyait 100 des que la periode precedente etait vide et que
     * la courante ne l'etait pas, si bien que la premiere semaine suivie par un
     * utilisateur s'affichait « +100 % vs sem. passee » : un gain invente contre
     * une semaine qui n'existe pas, et d'autant plus credible qu'il ressemblait
     * a un vrai chiffre. Mesure avant correction : current=500, previous=0,
     * percentage=100.0 (#1388).
     */
    public function test_weekly_comparison_has_no_percentage_without_a_previous_week(): void
    {
        $user = User::factory()->create();

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->startOfWeek()->addHour(),
        ]);
        $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
        Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50, 'reps' => 10]);

        $comparison = app(VolumeStatsService::class)->getWeeklyVolumeComparison($user);

        $this->assertEquals(500, $comparison->current_volume);
        $this->assertEquals(0, $comparison->previous_volume);
        $this->assertNull($comparison->percentage);
    }

    /**
     * Zero est un resultat, pas une absence : deux semaines au meme volume se
     * comparent tres bien. C'est la distinction que la valeur de repli effacait.
     */
    public function test_weekly_comparison_reports_zero_when_the_two_weeks_match(): void
    {
        $user = User::factory()->create();

        foreach ([now()->startOfWeek()->addHour(), now()->subWeek()->startOfWeek()->addHour()] as $startedAt) {
            $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => $startedAt]);
            $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
            Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 50, 'reps' => 10]);
        }

        $comparison = app(VolumeStatsService::class)->getWeeklyVolumeComparison($user);

        $this->assertEquals(500, $comparison->current_volume);
        $this->assertEquals(500, $comparison->previous_volume);
        $this->assertSame(0.0, $comparison->percentage);
    }

    public function test_can_get_volume_history(): void
    {
        $user = User::factory()->create();

        // Workout 1: 100kg * 10 reps = 1000
        $workout1 = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
            'name' => 'Workout 1',
        ]);
        $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id]);
        Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 100, 'reps' => 10]);

        // Workout 2: 50kg * 10 reps = 500
        $workout2 = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay(),
            'ended_at' => now()->subDay()->addHour(),
            'name' => 'Workout 2',
        ]);
        $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id]);
        Set::factory()->create(['workout_line_id' => $line2->id, 'weight' => 50, 'reps' => 10]);

        $history = app(VolumeStatsService::class)->getVolumeHistory($user);

        $this->assertCount(2, $history);
        // History is returned oldest first
        $this->assertEquals('Workout 1', $history[0]->name);
        $this->assertEquals(1000, $history[0]->volume);
        $this->assertEquals('Workout 2', $history[1]->name);
        $this->assertEquals(500, $history[1]->volume);
    }

    public function test_can_retrieve_duration_history(): void
    {
        $user = User::factory()->create();

        // Workout 1: 60 minutes
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2)->hour(10)->minute(0),
            'ended_at' => now()->subDays(2)->hour(11)->minute(0),
        ]);

        // Workout 2: 90 minutes
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay()->hour(10)->minute(0),
            'ended_at' => now()->subDay()->hour(11)->minute(30),
        ]);

        // Workout 3: 45 minutes, ended_at before started_at (should be handled as absolute)
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->hour(10)->minute(45),
            'ended_at' => now()->hour(10)->minute(0),
        ]);

        $history = app(WorkoutStatsService::class)->getDurationHistory($user);

        $this->assertCount(3, $history);

        // Check order (oldest first due to reverse())
        $this->assertEquals(60, $history[0]->duration);
        $this->assertEquals(90, $history[1]->duration);
        $this->assertEquals(45, $history[2]->duration); // Should be absolute difference
    }

    /**
     * Une seance qui ne tombe pas sur la minute est tronquee, pas arrondie.
     *
     * Le test voisin n'emploie que des durees en minutes entieres : `floor`,
     * `round` et `ceil` y donnent le meme resultat, donc rien ne distinguait
     * les trois. Une minute et demie les separe — 1 par troncature, 2 par
     * arrondi ou par exces.
     *
     * Ce n'est pas un detail d'affichage indifferent : c'est la duree que
     * l'utilisateur lit sur son historique, et deux implementations
     * raisonnables donnent deux chiffres differents. Le choix doit etre fixe
     * quelque part, sans quoi il se reinvente au premier remaniement.
     */
    public function test_duration_history_truncates_partial_minutes(): void
    {
        $user = User::factory()->create();

        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDay()->hour(10)->minute(0)->second(0),
            'ended_at' => now()->subDay()->hour(10)->minute(1)->second(30),
        ]);

        $history = app(WorkoutStatsService::class)->getDurationHistory($user);

        $this->assertCount(1, $history);
        $this->assertSame(1, $history[0]->duration);
    }
}
