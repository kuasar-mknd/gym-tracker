<?php

declare(strict_types=1);

use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Queue;

/*
 * Records, succès et objectifs ne lisent que des séries cochées : une série qui
 * ne l'est pas ne met rien en file, sauf quand elle vient de cesser de l'être.
 */
function serieDeTest(bool $cochee): Set
{
    $compte = User::factory()->create();
    $ligne = WorkoutLine::factory()->create([
        'workout_id' => Workout::factory()->create(['user_id' => $compte->id])->id,
    ]);

    return Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => 80,
        'reps' => 5,
        'is_completed' => $cochee,
    ]);
}

it('ne met rien en file quand on corrige une série non cochée', function (): void {
    $serie = serieDeTest(false);
    Queue::fake();

    $serie->update(['weight' => 85]);

    Queue::assertNotPushed(SyncUserGoals::class);
    Queue::assertNotPushed(SyncUserAchievements::class);
});

it('met les recalculs en file quand on corrige une série cochée', function (): void {
    $serie = serieDeTest(true);
    Queue::fake();

    $serie->update(['weight' => 85]);

    Queue::assertPushed(SyncUserGoals::class);
    Queue::assertPushed(SyncUserAchievements::class);
});

it('met les recalculs en file quand une série vient d’être cochée', function (): void {
    $serie = serieDeTest(false);
    Queue::fake();

    $serie->update(['is_completed' => true]);

    Queue::assertPushed(SyncUserGoals::class);
    Queue::assertPushed(SyncUserAchievements::class);
});

it('met les recalculs en file quand une série vient d’être décochée', function (): void {
    $serie = serieDeTest(true);
    Queue::fake();

    $serie->update(['is_completed' => false]);

    Queue::assertPushed(SyncUserGoals::class);
    Queue::assertPushed(SyncUserAchievements::class);
});

it('met les recalculs en file quand une autre requête a coché la série entre la liaison et l’écriture', function (): void {
    $serie = serieDeTest(false);
    $perimee = Set::query()->findOrFail($serie->id);
    Set::query()->findOrFail($serie->id)->update(['is_completed' => true]);
    Queue::fake();

    $perimee->update(['weight' => 100]);

    Queue::assertPushed(SyncUserGoals::class);
    Queue::assertPushed(SyncUserAchievements::class);
});
