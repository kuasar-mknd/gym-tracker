<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\TrainingReminder;
use App\Services\Stats\WorkoutStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * Une séance « faite » se lit partout de la même façon (#2333) : terminée, avec
 * au moins une série cochée (`User::seancesFaites()`).
 *
 * Les succès, les objectifs et la série de jours la lisaient ainsi depuis #2110
 * et #2171 ; les fréquences de la page Séances, la répartition horaire et le
 * rappel d'entraînement comptaient encore toute séance démarrée, y compris
 * celle qu'on ouvre le matin et qu'on abandonne vide.
 */

/**
 * Une séance démarrée à l'heure dite, sans aucune série : ce que donne « Démarrer ».
 */
function seanceDemarreeVide(User $user, string $debut): Workout
{
    return Workout::factory()->create(['user_id' => $user->id, 'started_at' => $debut, 'ended_at' => null]);
}

/**
 * Une séance faite : terminée, avec une série cochée.
 */
function seanceFaiteLe(User $user, string $debut, ?string $fin = null): Workout
{
    return Workout::withoutEvents(fn (): Workout => Workout::factory()->faite()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => $fin ?? CarbonImmutable::parse($debut)->addHour()->toDateTimeString(),
    ]));
}

it('ne compte pas les séances démarrées sans série cochée dans les fréquences de la page Séances', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    // Un lundi fait, un lundi démarré vide (jamais terminé), un lundi terminé sans série cochée.
    seanceFaiteLe($user, '2026-08-24 10:00:00');
    seanceDemarreeVide($user, '2026-08-17 10:00:00');
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-08-10 10:00:00', 'ended_at' => '2026-08-10 11:00:00']);

    Cache::flush();
    $graphiques = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts'];

    $lundi = $graphiques['day_of_week_frequency']->firstWhere('day', 'Lun');
    $aout = $graphiques['monthly_frequency']->last();

    expect($lundi['count'] ?? null)->toBe(1)
        ->and($aout['count'] ?? null)->toBe(1);
});

it('ne compte pas les séances démarrées sans série cochée dans la répartition horaire', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    seanceFaiteLe($user, '2026-08-24 19:00:00');
    seanceDemarreeVide($user, '2026-08-25 07:00:00');

    Cache::flush();
    $repartition = app(WorkoutStatsService::class)->repartitionsDesSeances($user);
    $parCreneau = collect($repartition['time_of_day'])->mapWithKeys(fn ($creneau): array => [$creneau->label => $creneau->count]);

    expect($parCreneau->sum())->toBe(1)
        ->and($parCreneau[__('Evening (17h-22h)')])->toBe(1)
        ->and($parCreneau[__('Morning (06h-12h)')])->toBe(0);
});

it('relit la répartition quand une séance terminée devient faite par une série cochée hors des contrôleurs', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    $terminee = Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-08-24 19:00:00', 'ended_at' => '2026-08-24 20:00:00']);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $terminee->id]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 20, 'reps' => 5, 'is_completed' => false]);

    $service = app(WorkoutStatsService::class);
    $total = fn (): int => array_sum(array_map(fn (\App\DTOs\Stats\DistributionStat $creneau): int => $creneau->count, $service->repartitionsDesSeances($user)['time_of_day']));

    expect($total())->toBe(0);

    // Le panneau coche la série : la séance devient faite sans passer par l'API.
    $serie->update(['is_completed' => true]);
    app()->forgetScopedInstances();

    expect($total())->toBe(1);

    $serie->update(['is_completed' => false]);
    app()->forgetScopedInstances();

    expect($total())->toBe(0);
});

it('relit la répartition quand la série cochée d\'une séance terminée est supprimée hors des contrôleurs', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    $seance = Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => '2026-08-24 19:00:00']);

    $service = app(WorkoutStatsService::class);
    $total = fn (): int => array_sum(array_map(fn (\App\DTOs\Stats\DistributionStat $creneau): int => $creneau->count, $service->repartitionsDesSeances($user)['time_of_day']));

    expect($total())->toBe(1);

    Set::query()->whereHas('workoutLine', fn ($requete) => $requete->where('workout_id', $seance->id))->first()?->delete();
    app()->forgetScopedInstances();

    expect($total())->toBe(0);
});

it('relit la répartition quand la ligne d\'une séance faite est supprimée hors des contrôleurs', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    $seance = Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => '2026-08-24 19:00:00']);

    $service = app(WorkoutStatsService::class);
    $total = fn (): int => array_sum(array_map(fn (\App\DTOs\Stats\DistributionStat $creneau): int => $creneau->count, $service->repartitionsDesSeances($user)['time_of_day']));

    expect($total())->toBe(1);

    $seance->workoutLines()->first()?->delete();
    app()->forgetScopedInstances();

    expect($total())->toBe(0);
});

/**
 * Un compte qui a choisi le lundi, un lundi à 18 h.
 */
function compteAvecRappelLeLundi(): User
{
    CarbonImmutable::setTestNow('2026-09-07 18:00:00');
    $user = User::factory()->create();
    $user->notificationPreferences()->create(['type' => 'training_reminder', 'is_enabled' => true, 'days' => [1]]);

    return $user;
}

it('rappelle à 18 h un compte qui a ouvert une séance le matin et l\'a abandonnée vide', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    seanceDemarreeVide($user, '2026-09-07 08:00:00');

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});

it('rappelle un compte dont la séance du matin est terminée sans série cochée', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-09-07 08:00:00', 'ended_at' => '2026-09-07 09:00:00']);

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});

it('ne rappelle pas un compte qui a fait une séance aujourd\'hui', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    seanceFaiteLe($user, '2026-09-07 08:00:00');

    Artisan::call('app:remind-training');

    Notification::assertNotSentTo($user, TrainingReminder::class);
});

it('ne compte que la journée en cours : une séance faite hier ne fait pas taire le rappel', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    seanceFaiteLe($user, '2026-09-06 20:00:00');

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});

it('rappelle encore un compte dont la séance du jour, cochée, n\'est pas terminée (choix du propriétaire, #2333)', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    $enCours = seanceDemarreeVide($user, '2026-09-07 17:30:00');
    $ligne = WorkoutLine::factory()->create(['workout_id' => $enCours->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => true]);

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});
