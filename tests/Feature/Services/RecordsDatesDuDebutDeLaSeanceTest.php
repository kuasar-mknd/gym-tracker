<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Un record est daté du début de la séance dont la série le tient, que la
 * synchronisation l'écrive (`update()`) ou que la reconstruction le réécrive
 * (`reconstruire()`), jamais de l'horloge : le rejeu d'une série saisie hors
 * ligne écrivait l'heure du travail de file, et la première reconstruction une
 * autre date, celle de la création de la série. Les dates se comparent telles
 * que la base les garde, pas par des Carbon.
 */

/** @return array{0: User, 1: Exercise, 2: Workout, 3: WorkoutLine} */
function sceneDateDesRecords(string $debut): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse($debut)]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user, $exercice, $seance, $ligne];
}

function serieDatee(WorkoutLine $ligne, float $poids, int $repetitions, ?string $creee = null): Set
{
    return Set::withoutEvents(fn (): Set => Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
        'is_completed' => true,
        'created_at' => $creee ?? now(),
    ]));
}

/**
 * Les dates des records, telles que la base les garde.
 *
 * @return list<string>
 */
function datesDesRecords(string $colonne, int $valeur): array
{
    return array_values(DB::table('personal_records')
        ->where($colonne, $valeur)
        ->orderBy('type')
        ->pluck('achieved_at')
        ->map(fn (mixed $date): string => is_string($date) ? $date : '')
        ->all());
}

function debutEnBase(Workout $seance): string
{
    $debut = DB::table('workouts')->where('id', $seance->id)->value('started_at');

    return is_string($debut) ? $debut : '';
}

it('date le record du début de la séance, par la synchronisation comme par la reconstruction', function (): void {
    Carbon::setTestNow('2026-11-13 18:00:00');
    [$user, $exercice, $seance, $ligne] = sceneDateDesRecords('2026-11-10 09:30:00');
    $serie = serieDatee($ligne, 100, 5, '2026-11-13 17:00:00');

    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($serie);
    $parLaSynchronisation = datesDesRecords('user_id', $user->id);

    Carbon::setTestNow('2026-11-14 08:00:00');
    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect($parLaSynchronisation)->toBe([debutEnBase($seance), debutEnBase($seance), debutEnBase($seance)])
        ->and(debutEnBase($seance))->toBe('2026-11-10 09:30:00')
        ->and(datesDesRecords('user_id', $user->id))->toBe($parLaSynchronisation);
});

it('date un record du jour de sa séance antidatée, pas de celui où on la saisit', function (): void {
    Carbon::setTestNow('2026-11-13 18:00:00');
    [$user, , , $ligne] = sceneDateDesRecords('2026-03-02 07:15:00');

    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie(serieDatee($ligne, 80, 8));

    expect(datesDesRecords('user_id', $user->id))->toBe(array_fill(0, 3, '2026-03-02 07:15:00'));
});

it('garde la date du geste quand le travail de file rejoue la série des heures plus tard', function (): void {
    Carbon::setTestNow('2026-11-13 09:00:00');
    [$user, $exercice, , $ligne] = sceneDateDesRecords('2026-11-13 09:00:00');
    $serie = serieDatee($ligne, 60, 10);

    Carbon::setTestNow('2026-11-13 21:40:00');
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($serie);
    $avant = datesDesRecords('user_id', $user->id);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect($avant)->toBe(array_fill(0, 3, '2026-11-13 09:00:00'))
        ->and(datesDesRecords('user_id', $user->id))->toBe($avant);
});

it('date chaque record de la séance qui porte sa série quand plusieurs séances se succèdent', function (): void {
    Carbon::setTestNow('2026-11-13 18:00:00');
    [$user, $exercice, , $ligne] = sceneDateDesRecords('2026-11-01 10:00:00');
    $seconde = Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-11-08 10:00:00')]);
    $ligneSeconde = WorkoutLine::factory()->create(['workout_id' => $seconde->id, 'exercise_id' => $exercice->id]);

    $service = app(PersonalRecordService::class);
    $service->synchroniserLesRecordsDeLaSerie(serieDatee($ligne, 100, 5));
    $service->synchroniserLesRecordsDeLaSerie(serieDatee($ligneSeconde, 120, 5));
    $apresLaSynchronisation = datesDesRecords('user_id', $user->id);
    $service->recompute($user, $exercice->id);

    expect($apresLaSynchronisation)->toBe(array_fill(0, 3, '2026-11-08 10:00:00'))
        ->and(datesDesRecords('user_id', $user->id))->toBe($apresLaSynchronisation);
});

it('suit le début de la séance quand on le modifie, et ne touche pas les records des autres séances', function (): void {
    Carbon::setTestNow('2026-11-13 18:00:00');
    [$user, $exercice, $seance, $ligne] = sceneDateDesRecords('2026-11-10 09:30:00');
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie(serieDatee($ligne, 100, 5));

    $autre = Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::parse('2026-11-11 09:30:00')]);
    $ligneAutre = WorkoutLine::factory()->create(['workout_id' => $autre->id, 'exercise_id' => Exercise::factory()->create(['user_id' => $user->id])->id]);
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie(serieDatee($ligneAutre, 50, 5));

    $seance->update(['started_at' => Carbon::parse('2026-11-09 18:45:00')]);

    expect(datesDesRecords('workout_id', $seance->id))->toBe(array_fill(0, 3, '2026-11-09 18:45:00'))
        ->and(datesDesRecords('workout_id', $autre->id))->toBe(array_fill(0, 3, '2026-11-11 09:30:00'));

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect(datesDesRecords('workout_id', $seance->id))->toBe(array_fill(0, 3, '2026-11-09 18:45:00'));
});

it('date la reconstruction du début de la séance quand la ligne a perdu sa copie ou la tient périmée', function (): void {
    Carbon::setTestNow('2026-11-13 18:00:00');
    [$user, $exercice, $seance, $ligne] = sceneDateDesRecords('2026-11-10 09:30:00');
    serieDatee($ligne, 100, 5);
    DB::table('workout_lines')->where('id', $ligne->id)->update(['workout_started_at' => '2020-01-01 00:00:00']);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect(datesDesRecords('user_id', $user->id))->toBe(array_fill(0, 3, debutEnBase($seance)))
        ->and(debutEnBase($seance))->toBe('2026-11-10 09:30:00');
});
