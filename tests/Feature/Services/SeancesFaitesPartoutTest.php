<?php

declare(strict_types=1);

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
use Tests\Support\DonneesDeProgres;

/*
 * Une séance « faite » se lit partout de la même façon (#2333) : terminée, avec
 * au moins une série cochée (`User::seancesFaites()`).
 *
 * Les succès, les objectifs et la série de jours la lisaient ainsi depuis #2110
 * et #2171 ; les fréquences de la page Séances et le
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
    $graphiques = DonneesDeProgres::graphiques($user);

    $lundi = $graphiques['day_of_week_frequency']->firstWhere('day', 'Lun');
    $aout = $graphiques['monthly_frequency']->last();

    expect($lundi['count'] ?? null)->toBe(1)
        ->and($aout['count'] ?? null)->toBe(1);
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

it('fait taire le rappel dès qu\'une série de travail est cochée aujourd\'hui, séance terminée ou non (choix du propriétaire, #2333)', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    $enCours = seanceDemarreeVide($user, '2026-09-07 17:30:00');
    $ligne = WorkoutLine::factory()->create(['workout_id' => $enCours->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => true]);

    Artisan::call('app:remind-training');

    Notification::assertNotSentTo($user, TrainingReminder::class);
});

it('rappelle encore un compte dont la séance du jour n\'a qu\'un échauffement coché, terminée ou non', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    $enCours = seanceDemarreeVide($user, '2026-09-07 17:30:00');
    $ligne = WorkoutLine::factory()->create(['workout_id' => $enCours->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => true, 'is_warmup' => true]);

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});

it('rappelle encore un compte dont la séance du jour n\'a qu\'une série de travail décochée', function (): void {
    Notification::fake();
    $user = compteAvecRappelLeLundi();

    $enCours = seanceDemarreeVide($user, '2026-09-07 17:30:00');
    $ligne = WorkoutLine::factory()->create(['workout_id' => $enCours->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => false, 'is_warmup' => false]);

    Artisan::call('app:remind-training');

    Notification::assertSentTo($user, TrainingReminder::class);
});

/**
 * Une séance terminée dont la seule série cochée est un échauffement n'est pas
 * faite (#2333) : ni les fréquences, ni la répartition, ni l'historique des
 * durées ne la comptent.
 */
function seanceTermineeAvecEchauffementSeul(User $user, string $debut): Workout
{
    return Workout::withoutEvents(function () use ($user, $debut): Workout {
        $seance = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => $debut,
            'ended_at' => CarbonImmutable::parse($debut)->addHour()->toDateTimeString(),
        ]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => true, 'is_warmup' => true]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'is_completed' => false, 'is_warmup' => false]);

        return $seance;
    });
}

it('ne compte pas une séance dont seul l\'échauffement est coché dans les fréquences', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    seanceFaiteLe($user, '2026-08-24 19:00:00');
    seanceTermineeAvecEchauffementSeul($user, '2026-08-17 07:00:00');

    Cache::flush();
    $graphiques = DonneesDeProgres::graphiques($user);

    expect($graphiques['day_of_week_frequency']->firstWhere('day', 'Lun')['count'] ?? null)->toBe(1)
        ->and($graphiques['monthly_frequency']->last()['count'] ?? null)->toBe(1)
        ->and($user->seancesFaites()->count())->toBe(1);
});

it('écarte de l\'historique des durées les séances terminées sans série de travail cochée, et garde la limite sur les séances faites', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    seanceFaiteLe($user, '2026-08-20 10:00:00', '2026-08-20 11:00:00');
    seanceFaiteLe($user, '2026-08-21 10:00:00', '2026-08-21 11:30:00');
    // Les plus récentes : terminée vide, échauffement seul, en cours.
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => '2026-08-22 10:00:00', 'ended_at' => '2026-08-22 10:05:00']);
    seanceTermineeAvecEchauffementSeul($user, '2026-08-23 10:00:00');
    seanceDemarreeVide($user, '2026-08-24 10:00:00');

    Cache::flush();
    $historique = app(WorkoutStatsService::class)->getDurationHistory($user, 2);

    expect($historique)->toHaveCount(2)
        ->and(array_map(fn (\App\DTOs\Stats\DurationHistoryPoint $point): string => $point->date, $historique))->toBe(['20/08', '21/08']);
});

it('refait la série de jours et renouvelle les statistiques quand une série cochée devient échauffement, puis redevient série de travail, dans une séance terminée', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00'));
    $user = User::factory()->create();

    $seance = Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => '2026-08-27 10:00:00']);
    $serie = Set::query()->whereHas('workoutLine', fn ($requete) => $requete->where('workout_id', $seance->id))->firstOrFail();

    $service = app(WorkoutStatsService::class);
    $total = fn (): int => count($service->getDurationHistory($user));

    expect($total())->toBe(1)
        ->and(User::query()->findOrFail($user->id)->current_streak)->toBe(1);

    $serie->update(['is_warmup' => true]);
    app()->forgetScopedInstances();

    expect($total())->toBe(0)
        ->and(User::query()->findOrFail($user->id)->current_streak)->toBe(0)
        ->and(User::query()->findOrFail($user->id)->last_workout_at)->toBeNull();

    $serie->update(['is_warmup' => false]);
    app()->forgetScopedInstances();

    expect($total())->toBe(1)
        ->and(User::query()->findOrFail($user->id)->current_streak)->toBe(1);
});
