<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;

/**
 * Détenteur du record de 1RM estimé après synchronisation puis après
 * reconstruction, pour deux séries données dans l'ordre (#2358).
 *
 * @param  array{0: float, 1: int}  $premiere
 * @param  array{0: float, 1: int}  $seconde
 * @return array{0: int, 1: int, 2: int, 3: int} Détenteur après synchronisation, après reconstruction, id de la première, id de la seconde.
 */
function rmEstimeDetenteurs(array $premiere, array $seconde): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $service = app(PersonalRecordService::class);

    $ids = [];
    foreach ([$premiere, $seconde] as $rang => [$poids, $repetitions]) {
        $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(10 - $rang)]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
        $serie = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => $repetitions, 'is_warmup' => false, 'is_completed' => true]);
        $service->synchroniserLesRecordsDeLaSerie($serie, $user->refresh());
        $ids[] = $serie->id;
    }

    $apresSynchronisation = (int) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->set_id;
    $service->recompute($user->refresh(), $exercice->id);
    $apresReconstruction = (int) PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_1rm')->firstOrFail()->set_id;

    return [$apresSynchronisation, $apresReconstruction, $ids[0], $ids[1]];
}

it('désigne le même détenteur du 1RM estimé par synchronisation et par reconstruction', function (float $poidsA, int $repsA, float $poidsB, int $repsB, int $rangDuDetenteur): void {
    [$synchronisation, $reconstruction, $idPremiere, $idSeconde] = rmEstimeDetenteurs([$poidsA, $repsA], [$poidsB, $repsB]);

    expect($reconstruction)->toBe($synchronisation)
        ->and($reconstruction)->toBe($rangDuDetenteur === 1 ? $idPremiere : $idSeconde);
})->with([
    'égalité exacte à la demi-unité, 250,75×27 puis 280,25×21 : 476,42 puis 476,43' => [250.75, 27, 280.25, 21, 2],
    'égalité exacte entière, 44×6 puis 48×3 : le détenteur le reste' => [44.0, 6, 48.0, 3, 1],
]);
