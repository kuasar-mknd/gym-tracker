<?php

declare(strict_types=1);

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function lecturesCalendrier(callable $geste): int
{
    $releve = function (): int {
        $total = 0;
        /** @var list<object{Value: string}> $compteurs */
        $compteurs = DB::select("show session status like 'Handler_read%'");

        foreach ($compteurs as $compteur) {
            $total += (int) $compteur->Value;
        }

        return $total;
    };

    $avant = $releve();
    $geste();

    return $releve() - $avant;
}

function semerSeancesEtLignes(User $user, int $seances): void
{
    $exercice = Exercise::factory()->create(['user_id' => null, 'name' => 'Développé couché']);
    $lignesSeances = [];

    for ($jour = 0; $jour < $seances; $jour++) {
        $quand = Carbon::now()->subDays($jour);
        $lignesSeances[] = ['user_id' => $user->id, 'name' => 'S'.$jour, 'started_at' => $quand, 'ended_at' => $quand->copy()->addHour(), 'workout_volume' => 1000, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach (array_chunk($lignesSeances, 500) as $lot) {
        DB::table('workouts')->insert($lot);
    }

    $lignes = [];

    foreach (DB::table('workouts')->where('user_id', $user->id)->pluck('id') as $id) {
        for ($rang = 0; $rang < 3; $rang++) {
            $lignes[] = ['workout_id' => $id, 'user_id' => $user->id, 'exercise_id' => $exercice->id, 'order' => $rang, 'created_at' => now(), 'updated_at' => now()];
        }
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workout_lines')->insert($lot);
    }
}

/**
 * Le calendrier n'affiche qu'un mois, et son cout ne suivait meme pas
 * l'historique de celui qui le regarde : joint a `exercises`, MySQL attaquait
 * par le CATALOGUE — `exercises_user_id_name_index` en tete de plan, plus une
 * table temporaire et un tri. Chaque exercice tirait alors ses lignes, TOUS
 * utilisateurs et tous mois confondus.
 *
 * D'ou la forme du temoin : ce qui doit rester immobile, c'est le cout d'un
 * compte pendant qu'un AUTRE compte grossit. Mesure sur `main`, les deux
 * comptes rendaient 868 lectures — le petit payait l'historique du grand.
 */
it('ne fait pas payer à un compte l’historique d’un autre', function (): void {
    $observe = User::factory()->create();
    semerSeancesEtLignes($observe, 30);

    $action = new FetchCalendarEventsAction();
    $annee = (int) now()->year;
    $mois = (int) now()->month;

    // Un premier voisin, deja gros : ce qui suit se mesure a plan stable, sans
    // quoi le passage du balayage complet au parcours d'index sur une table
    // minuscule brouillerait l'ecart qu'on veut lire.
    semerSeancesEtLignes(User::factory()->create(), 300);

    $action->execute($observe, $annee, $mois);
    $avant = lecturesCalendrier(fn (): array => $action->execute($observe, $annee, $mois));

    semerSeancesEtLignes(User::factory()->create(), 900);

    $apres = lecturesCalendrier(fn (): array => $action->execute($observe, $annee, $mois));

    expect($apres)->toBe($avant);
});

it('rend les trois premiers exercices de chaque séance', function (): void {
    $user = User::factory()->create();
    $seanceId = DB::table('workouts')->insertGetId([
        'user_id' => $user->id, 'name' => 'Haut du corps', 'started_at' => now(),
        'ended_at' => now()->addHour(), 'workout_volume' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['Développé', 'Tirage', 'Curl', 'Gainage'] as $rang => $nom) {
        $exercice = Exercise::factory()->create(['user_id' => $user->id, 'name' => $nom]);
        DB::table('workout_lines')->insert([
            'workout_id' => $seanceId, 'user_id' => $user->id, 'exercise_id' => $exercice->id,
            'order' => $rang, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $action = new FetchCalendarEventsAction();
    $evenements = $action->execute($user, (int) now()->year, (int) now()->month);
    $premiere = $evenements['workouts']->first();

    expect($premiere)->not->toBeNull()
        ->and($premiere['preview_exercises'] ?? [])->toBe(['Développé', 'Tirage', 'Curl'])
        ->and($premiere['exercises_count'] ?? 0)->toBe(4);
});
