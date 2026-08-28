<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

/**
 * `GET /api/v1/sets` filtrait par une double jointure — `sets` vers
 * `workout_lines` vers `workouts` — et ordonnait sur `sets`. Aucun index ne
 * peut servir les deux : MySQL materialisait la jointure entiere et la triait
 * avant d'en prendre quinze lignes. 484 lectures d'index pour 120 series,
 * 2 802 pour 600, avec `Using temporary; Using filesort` au plan.
 */
it('sert la liste des séries sans joindre les séances', function (): void {
    $user = User::factory()->create();
    semerSeriesDe($user, 10);
    Sanctum::actingAs($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $reponse = $this->getJson(route('api.v1.sets.index'))->assertOk();
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    expect($reponse->json('meta.total'))->toBe(30)
        ->and($reponse->json('data'))->toHaveCount(15);

    foreach ($requetes as $sql) {
        if (! str_contains($sql, 'from `sets`')) {
            continue;
        }

        expect($sql)->not->toContain('join `workout_lines`')
            ->and($sql)->not->toContain('join `workouts`');
    }
});

it('ne rend pas les séries d’un autre utilisateur', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    semerSeriesDe($autre, 5);
    Sanctum::actingAs($user);

    expect($this->getJson(route('api.v1.sets.index'))->assertOk()->json('meta.total'))->toBe(0);
});

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
