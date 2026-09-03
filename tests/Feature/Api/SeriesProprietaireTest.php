<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function semerSeriesDe(User $user, int $seances): void
{
    $exercice = Exercise::factory()->create(['user_id' => null]);
    $lignesSeances = [];

    for ($rang = 0; $rang < $seances; $rang++) {
        $quand = Carbon::now()->subDays($rang);
        $lignesSeances[] = ['user_id' => $user->id, 'name' => 'S'.$rang, 'started_at' => $quand, 'ended_at' => $quand->copy()->addHour(), 'workout_volume' => 10, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach (array_chunk($lignesSeances, 500) as $lot) {
        DB::table('workouts')->insert($lot);
    }

    $lignes = [];

    foreach (DB::table('workouts')->where('user_id', $user->id)->get(['id', 'started_at']) as $seance) {
        if (DB::table('workout_lines')->where('workout_id', $seance->id)->exists()) {
            continue;
        }

        $lignes[] = ['workout_id' => $seance->id, 'user_id' => $user->id, 'workout_started_at' => $seance->started_at, 'exercise_id' => $exercice->id, 'order' => 0, 'created_at' => now(), 'updated_at' => now()];
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workout_lines')->insert($lot);
    }

    $series = [];

    foreach (DB::table('workout_lines')->where('user_id', $user->id)->pluck('id') as $ligneId) {
        if (DB::table('sets')->where('workout_line_id', $ligneId)->exists()) {
            continue;
        }

        for ($k = 0; $k < 3; $k++) {
            $series[] = ['workout_line_id' => $ligneId, 'user_id' => $user->id, 'weight' => 50, 'reps' => 10, 'is_completed' => true, 'is_warmup' => false, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk($series, 500) as $lot) {
        DB::table('sets')->insert($lot);
    }
}

it('pose le propriétaire à l’écriture', function (): void {
    $seance = Workout::factory()->create();
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);

    $serie = Set::factory()->create(['workout_line_id' => $ligne->id]);

    expect($serie->refresh()->user_id)->toBe($seance->user_id);
});

it('suit le propriétaire quand la série change de ligne', function (): void {
    $depart = WorkoutLine::factory()->create();
    $arrivee = WorkoutLine::factory()->create();
    $serie = Set::factory()->create(['workout_line_id' => $depart->id]);

    $serie->update(['workout_line_id' => $arrivee->id]);

    expect($serie->refresh()->user_id)->toBe($arrivee->refresh()->user_id);
});
