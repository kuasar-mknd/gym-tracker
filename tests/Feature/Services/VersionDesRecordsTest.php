<?php

declare(strict_types=1);

use App\Jobs\SyncPersonalRecord;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;

/*
 * La version des records du compte ne se renouvelle que si une section sous le
 * verrou a écrit ou retiré un record (#2434) : une écriture de cache de plus
 * sur chaque correction de série qui ne tient aucun record, ou chaque travail
 * de file qui ne bat rien, coûte cher sur un disque lent.
 */

/**
 * Le nombre de fois où la version des records du compte s'écrit pendant la fonction.
 */
function versionsDesRecordsEcrites(User $compte, Closure $travail): int
{
    $ecrites = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $ecriture) use (&$ecrites, $compte): void {
        if ($ecriture->key === "stats-version.records.{$compte->id}") {
            $ecrites++;
        }
    });

    $travail();

    return $ecrites;
}

/**
 * @return array{0: User, 1: Exercise, 2: Set, 3: Set} Le compte, l'exercice, une série lourde et une série légère du même exercice.
 */
function uneSerieLourdeEtUneLegere(): array
{
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    $serie = fn (float $poids): Set => Set::factory()->createQuietly([
        'workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false,
    ]);

    return [$compte, $exercice, $serie(100), $serie(60)];
}

it('renouvelle la version des records une fois quand une série bat un record', function (): void {
    [$compte, , $lourde] = uneSerieLourdeEtUneLegere();

    expect(versionsDesRecordsEcrites($compte, fn () => app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($lourde, $compte)))->toBe(1);
});

it('ne renouvelle pas la version des records pour une série qui ne bat rien', function (): void {
    [$compte, , $lourde, $legere] = uneSerieLourdeEtUneLegere();
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($lourde, $compte);

    expect(versionsDesRecordsEcrites($compte, fn () => app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($legere, $compte)))->toBe(0);
});

it('ne renouvelle pas la version des records pour un travail de file qui ne bat rien', function (): void {
    [$compte, , $lourde, $legere] = uneSerieLourdeEtUneLegere();
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($lourde, $compte);

    expect(versionsDesRecordsEcrites($compte, fn () => new SyncPersonalRecord(Set::query()->findOrFail($legere->id), $compte)->handle(app(PersonalRecordService::class))))->toBe(0);
});

it('ne renouvelle pas la version des records pour une série qui ne tient aucun record', function (): void {
    [$compte, , $lourde, $legere] = uneSerieLourdeEtUneLegere();
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($lourde, $compte);

    expect(versionsDesRecordsEcrites($compte, fn () => app(PersonalRecordService::class)->refreshRecordsHeldBy($legere, $compte)))->toBe(0);
});

it('renouvelle la version des records quand la reconstruction en retire un', function (): void {
    [$compte, $exercice, $lourde] = uneSerieLourdeEtUneLegere();
    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($lourde, $compte);
    Set::query()->whereKey($lourde->id)->delete();

    expect(versionsDesRecordsEcrites($compte, fn () => app(PersonalRecordService::class)->recompute($compte, $exercice->id)))->toBe(1);
});
