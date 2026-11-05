<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\Achievement;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\AchievementService;
use App\Services\GoalService;
use App\Services\StreakService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
 * Les succès de séances et les objectifs de fréquence appliquent la même
 * définition d'une séance faite : terminée, avec au moins une série cochée,
 * sur n'importe laquelle de ses lignes (#2110). Les deux comptaient toutes
 * les séances du compte. La série de jours, son succès et le contrôle
 * nocturne l'appliquent aussi aux jours (#2171).
 *
 * Un même compte porte chaque sorte de séance, et les trois lecteurs (le
 * succès, le lot des objectifs, le chemin d'un seul objectif) doivent en
 * compter le même nombre. Les séances s'écrivent sans écouteurs : aucun
 * recalcul ne part avant celui qu'on mesure (`.ai/rules/tests.md`).
 */

/**
 * Une séance du compte, terminée ou non, avec une ligne par série donnée
 * (`true` : cochée), plus `$lignesVides` lignes sans série. Elle commence il y
 * a deux heures, ou à l'instant donné.
 *
 * @param  list<bool>  $series
 */
function seanceFaiteOuNon(User $compte, bool $terminee, array $series, int $lignesVides = 0, bool $echauffement = false, ?Carbon $debut = null): void
{
    $debut ??= now()->subHours(2);

    Workout::withoutEvents(function () use ($compte, $terminee, $series, $lignesVides, $echauffement, $debut): void {
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => $debut,
            'ended_at' => $terminee ? $debut->copy()->addHour() : null,
        ]);

        foreach ($series as $cochee) {
            Set::factory()->create([
                'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
                'is_completed' => $cochee,
                'is_warmup' => $echauffement,
            ]);
        }

        WorkoutLine::factory()->count($lignesVides)->create(['workout_id' => $seance->id]);
    });
}

/**
 * Un compte de trois séances faites, et de chaque sorte de séance qui ne
 * l'est pas.
 */
function seancesFaitesCompteMele(): User
{
    $compte = User::factory()->create();

    // Faites.
    seanceFaiteOuNon($compte, terminee: true, series: [true]);
    seanceFaiteOuNon($compte, terminee: true, series: [false, true]);
    seanceFaiteOuNon($compte, terminee: true, series: [true], echauffement: true);

    // Pas faites.
    seanceFaiteOuNon($compte, terminee: false, series: [true]);
    seanceFaiteOuNon($compte, terminee: true, series: []);
    seanceFaiteOuNon($compte, terminee: true, series: [], lignesVides: 2);
    seanceFaiteOuNon($compte, terminee: true, series: [false, false]);

    // Faite, mais par un autre compte.
    seanceFaiteOuNon(User::factory()->create(), terminee: true, series: [true]);

    return $compte;
}

it('compte trois séances faites, par la relation comme par withCount', function (): void {
    $compte = seancesFaitesCompteMele();

    expect($compte->seancesFaites()->count())->toBe(3)
        ->and(User::query()->withCount('seancesFaites')->findOrFail($compte->id)->seances_faites_count)->toBe(3)
        ->and($compte->workouts()->count())->toBe(7);
});

it('débloque un succès de séances au seuil des séances faites, pas au-delà', function (): void {
    Notification::fake();
    $compte = seancesFaitesCompteMele();

    $atteint = Achievement::factory()->create(['type' => 'count', 'threshold' => 3, 'slug' => 'trois-faites']);
    $manque = Achievement::factory()->create(['type' => 'count', 'threshold' => 4, 'slug' => 'quatre-faites']);

    app(AchievementService::class)->syncAchievements($compte);

    expect($compte->achievements()->pluck('achievements.id')->all())->toBe([$atteint->id])
        ->and($compte->achievements()->whereKey($manque->id)->exists())->toBeFalse();
});

it('compte les mêmes séances faites par le lot des objectifs et par le chemin d’un seul objectif', function (): void {
    $compte = seancesFaitesCompteMele();

    $parLeLot = Goal::factory()->create([
        'user_id' => $compte->id,
        'type' => GoalType::Frequency,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 3,
        'completed_at' => null,
    ]);

    app(GoalService::class)->syncGoals($compte);

    $seul = Goal::factory()->make([
        'user_id' => $compte->id,
        'type' => GoalType::Frequency,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'target_value' => 4,
    ]);

    app(GoalService::class)->updateGoalProgress($seul);

    $parLeLot->refresh();

    expect($parLeLot->current_value)->toBe(3.0)
        ->and($parLeLot->completed_at)->not->toBeNull()
        ->and($seul->current_value)->toBe(3.0)
        ->and($seul->progress_pct)->toBe(75.0)
        ->and($seul->completed_at)->toBeNull();
});

it('compte les mêmes jours faits pour la série de jours, son succès et le contrôle nocturne', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-10-07 18:00:00');

    $compte = User::factory()->create();
    $jour = fn (int $recul): Carbon => Carbon::parse('2026-10-07 10:00:00')->subDays($recul);

    // Sept jours de suite, chacun avec une sorte de séance : trois sont faits.
    seanceFaiteOuNon($compte, terminee: true, series: [true], debut: $jour(6));
    seanceFaiteOuNon($compte, terminee: true, series: [true], echauffement: true, debut: $jour(5));
    seanceFaiteOuNon($compte, terminee: false, series: [true], debut: $jour(4));
    seanceFaiteOuNon($compte, terminee: true, series: [], debut: $jour(3));
    seanceFaiteOuNon($compte, terminee: true, series: [], lignesVides: 2, debut: $jour(2));
    seanceFaiteOuNon($compte, terminee: true, series: [false, false], debut: $jour(1));
    seanceFaiteOuNon($compte, terminee: true, series: [false, true], debut: $jour(0));

    // Fait le 3, par un autre compte : il ne relie rien.
    $autre = User::factory()->create();
    seanceFaiteOuNon($autre, terminee: true, series: [true], debut: $jour(3));

    $deux = Achievement::factory()->create(['type' => 'streak', 'threshold' => 2, 'slug' => 'deux-jours-faits']);
    Achievement::factory()->create(['type' => 'streak', 'threshold' => 3, 'slug' => 'trois-jours-faits']);

    $series = app(StreakService::class);
    $series->recalculerDepuisLesFaits($compte);
    $series->recalculerDepuisLesFaits($autre);
    app(AchievementService::class)->syncAchievements($compte);

    expect($series->serieDepuisLesFaits($compte))->toBe(['derniere' => '2026-10-07 10:00:00', 'enCours' => 1, 'plusLongue' => 2])
        ->and($compte->achievements()->pluck('achievements.id')->all())->toBe([$deux->id]);

    test()->artisan('app:verify-data-coherence')
        ->expectsOutputToContain('OK date de dernière séance')
        ->expectsOutputToContain('OK série de jours');

    Carbon::setTestNow();
});
