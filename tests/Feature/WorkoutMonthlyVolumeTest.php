<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkoutMonthlyVolumeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une séance du mois précédent tombe dans l'avant-dernière barre, quel que soit le jour du mois.
     *
     * Le 31, `now()->subMonth()` déborde sur le mois en cours (le 31 septembre devient le 1er octobre) : la séance
     * se pose par `subMonthNoOverflow()`, et l'instant est fixé pour que chaque passage éprouve les fins de mois
     * (#2173 : la passe du 31 octobre était rouge, celles des autres jours vertes).
     */
    #[DataProvider('joursDuMois')]
    public function test_workouts_index_has_monthly_volume_prop(string $maintenant): void
    {
        $this->travelTo($maintenant);

        $user = User::factory()->create();

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subMonthNoOverflow(),
            'ended_at' => now()->subMonthNoOverflow()->addHour(),
        ]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
        ]);

        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 100,
            'reps' => 10, // Volume = 1000
        ]);

        $this->actingAs($user)
            ->get('/workouts')
            ->assertStatus(200)
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('Workouts/Index')
                    ->loadDeferredProps(fn (Assert $page): Assert => $page
                        ->has('deferredData.charts')
                        ->where('deferredData.charts.monthly_volume.4.volume', 1000) // 4th index is last month (5 months ago to current month = 6 items. Index 0=5 months ago, 5=current. Last month=4)
                    )
            );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function joursDuMois(): array
    {
        return [
            'le milieu du mois' => ['2026-11-15 10:00:00'],
            'un 31 après un mois de 30 jours' => ['2026-10-31 10:00:00'],
            'un 31 après février' => ['2027-03-31 10:00:00'],
            'un 1er du mois' => ['2026-11-01 10:00:00'],
        ];
    }
}
