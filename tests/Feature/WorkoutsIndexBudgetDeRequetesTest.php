<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Un compte avec une grande bibliotheque : vingt seances qui, ensemble,
 * touchent quatre-vingts exercices differents.
 */
function semerUneGrandeBibliotheque(User $user, int $seances = 20, int $exercices = 80): void
{
    $bibliotheque = Exercise::factory()->count($exercices)->create(['user_id' => null]);

    for ($rang = 0; $rang < $seances; $rang++) {
        $seance = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays($rang),
            'ended_at' => now()->subDays($rang)->addHour(),
        ]);

        foreach ($bibliotheque->slice($rang * 4, 4) as $exercice) {
            WorkoutLine::factory()->create([
                'workout_id' => $seance->id,
                'user_id' => $user->id,
                'exercise_id' => $exercice->id,
                'workout_started_at' => $seance->started_at,
            ]);
        }
    }
}

/**
 * @return list<string>
 */
function requetesDeLaPage(User $user, string $url): array
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($user)->get($url)->assertOk();

    $requetes = array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    return $requetes;
}

/**
 * La page des seances comptait ses exercices distincts par un saut d'index,
 * une requete par exercice trouve : juste en lectures, mais quatre-vingts
 * allers-retours pour un compte de quatre-vingts exercices, a chaque
 * expiration du cache. Le meme saut tient en une seule instruction.
 */
it('tient sous quinze requêtes à froid, quel que soit le nombre d’exercices', function (): void {
    $user = User::factory()->create();
    semerUneGrandeBibliotheque($user);

    $requetes = requetesDeLaPage($user, route('workouts.index'));

    expect(count($requetes))->toBeLessThanOrEqual(15, implode("\n", $requetes));
});
