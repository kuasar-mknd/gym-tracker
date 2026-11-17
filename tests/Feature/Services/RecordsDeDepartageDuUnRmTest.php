<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pose chaque série dans sa séance, la plus ancienne d'abord, et rend leurs
 * identifiants dans l'ordre.
 *
 * @param  list<array{0: float, 1: int}>  $series
 * @return array{0: User, 1: Exercise, 2: list<int>}
 */
function departageDuUnRmScene(array $series, bool $synchroniser): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $service = app(PersonalRecordService::class);
    $ids = [];

    foreach ($series as $rang => [$poids, $repetitions]) {
        $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(20 - $rang)]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
        $serie = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => $repetitions, 'is_warmup' => false, 'is_completed' => true]);

        if ($synchroniser) {
            $service->synchroniserLesRecordsDeLaSerie($serie, $user->refresh());
        }

        $ids[] = $serie->id;
    }

    return [$user->refresh(), $exercice, $ids];
}

function departageDuUnRmDetenteur(User $user): int
{
    return (int) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->set_id;
}

it('garde pour la reconstruction le détenteur que la synchronisation a posé', function (float $poidsA, int $repsA, float $poidsB, int $repsB): void {
    $a = [$poidsA, $repsA];
    $b = [$poidsB, $repsB];

    foreach ([[$a, $b], [$b, $a]] as $ordre) {
        [$user, $exercice, $ids] = departageDuUnRmScene($ordre, true);

        $parSynchronisation = departageDuUnRmDetenteur($user);

        app(PersonalRecordService::class)->recompute($user, $exercice->id);

        expect(departageDuUnRmDetenteur($user))->toBe($parSynchronisation)
            ->and(in_array($parSynchronisation, $ids, true))->toBeTrue();
    }
})->with([
    'égalité exacte à la demi-unité (476,425)' => [250.75, 27, 280.25, 21],
    '22,75×21 puis 29,75×9' => [22.75, 21, 29.75, 9],
    '261,25×21 puis 242,25×25' => [261.25, 21, 242.25, 25],
    '60×10 puis 72,73×3' => [60.0, 10, 72.73, 3],
    '200×20 puis 333,34×1' => [200.0, 20, 333.34, 1],
    '44×6 puis 48×3' => [44.0, 6, 48.0, 3],
]);

it('départage 250,75×27 et 280,25×21 comme la synchronisation : la valeur arrondie, puis la série la plus ancienne', function (): void {
    [$user, $exercice, $ids] = departageDuUnRmScene([[250.75, 27], [280.25, 21]], true);

    // PHP les arrondit à 476,42 puis 476,43 : la seconde gagne.
    expect(departageDuUnRmDetenteur($user))->toBe($ids[1]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect(departageDuUnRmDetenteur($user))->toBe($ids[1]);
});

it('à valeur arrondie égale, la série la plus ancienne tient, quel que soit son rang en base', function (): void {
    [$user, $exercice, $ids] = departageDuUnRmScene([[100.0, 6], [100.0, 6]], true);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect(departageDuUnRmDetenteur($user))->toBe($ids[0]);
});

it('ne change pas la valeur affichée : 27,75×19 reste 45,32', function (): void {
    [$user, $exercice] = departageDuUnRmScene([[27.75, 19]], false);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect((float) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->value)->toBe(45.32);
});

it('lit les records en une requête, avec ou sans égalité à départager', function (array $series, float $valeur): void {
    /** @var list<array{0: float, 1: int}> $series */
    [$user, $exercice] = departageDuUnRmScene($series, false);
    $service = app(PersonalRecordService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $attendus = $service->recordsAttendus($user->id, $exercice->id);
    $lectures = count(DB::getQueryLog());
    DB::flushQueryLog();
    $service->recompute($user, $exercice->id);
    $reconstruction = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($attendus['max_1rm']['valeur'])->toBe($valeur)
        ->and($lectures)->toBe(1)
        ->and($reconstruction)->toBe(6);
})->with([
    'sans égalité' => [[[100.0, 6], [60.0, 10]], 120.0],
    'avec égalité exacte' => [[[250.75, 27], [280.25, 21]], 476.43],
    'avec la meilleure série répétée' => [[[100.0, 6], [100.0, 6], [100.0, 6]], 120.0],
]);

it('garde pour la reconstruction le détenteur posé par la synchronisation, même si la série la plus récente est synchronisée la première', function (float $poidsA, int $repsA, float $poidsB, int $repsB): void {
    [$user, $exercice, $ids] = departageDuUnRmScene([[$poidsA, $repsA], [$poidsB, $repsB]], false);
    $service = app(PersonalRecordService::class);

    foreach (array_reverse($ids) as $id) {
        $service->synchroniserLesRecordsDeLaSerie(Set::query()->findOrFail($id), $user);
    }

    $parSynchronisation = departageDuUnRmDetenteur($user);
    $valeur = (float) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->value;

    $service->recompute($user, $exercice->id);

    expect(departageDuUnRmDetenteur($user))->toBe($parSynchronisation)
        ->and((float) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->value)->toBe($valeur);
})->with([
    '60×10 puis 72,73×3' => [60.0, 10, 72.73, 3],
    '250,75×27 puis 280,25×21' => [250.75, 27, 280.25, 21],
    '100×6 puis 100×6' => [100.0, 6, 100.0, 6],
    '44×6 puis 48×3' => [44.0, 6, 48.0, 3],
]);

it('ne notifie pas un record quand une série plus ancienne reprend le détenteur à valeur égale', function (): void {
    Notification::fake();
    [$user, , $ids] = departageDuUnRmScene([[100.0, 6], [100.0, 6]], false);
    $service = app(PersonalRecordService::class);

    $service->synchroniserLesRecordsDeLaSerie(Set::query()->findOrFail($ids[1]), $user);
    Notification::fake();
    $service->synchroniserLesRecordsDeLaSerie(Set::query()->findOrFail($ids[0]), $user);

    expect(departageDuUnRmDetenteur($user))->toBe($ids[0]);
    Notification::assertNothingSent();
});
