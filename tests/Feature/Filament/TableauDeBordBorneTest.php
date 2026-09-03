<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function lecturesTableauDeBord(callable $geste): int
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

function seancesAnciennes(User $user, int $combien, int $decalage): void
{
    $lignes = [];

    for ($rang = 0; $rang < $combien; $rang++) {
        $quand = Carbon::now()->subDays($decalage + $rang)->setTime(9, 0);
        $lignes[] = ['user_id' => $user->id, 'name' => 'A'.$decalage.'-'.$rang, 'started_at' => $quand, 'ended_at' => $quand->copy()->addHour(), 'workout_volume' => 10, 'created_at' => $quand, 'updated_at' => now()];
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workouts')->insert($lot);
    }
}

/**
 * « Combien de seances aujourd'hui, tous comptes confondus » n'a pas de filtre
 * utilisateur, et les trois index de `workouts` commencent par `user_id` :
 * aucun ne bornait cette plage. Le plan disait `type: index`, soit le parcours
 * integral — 202 lectures pour 200 seances, 1 202 pour 1 200.
 *
 * Le temoin mesure donc ce qui doit rester immobile : le cout du comptage
 * pendant que l'HISTORIQUE grossit, a nombre de seances du jour constant.
 */
it('ne compte pas tout l’historique pour les séances du jour', function (): void {
    $user = User::factory()->create();

    Workout::factory()->count(3)->create([
        'user_id' => $user->id,
        'started_at' => Carbon::today()->setTime(9, 0),
    ]);

    // Un premier lot avant la mesure : sur une table minuscule MySQL balaie au
    // lieu de parcourir l'index, et ce basculement brouillerait l'ecart.
    seancesAnciennes($user, 300, 10);

    $compter = fn (): int => Workout::whereBetween(
        'started_at',
        [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()]
    )->count();

    $compter();
    $avant = lecturesTableauDeBord($compter);

    seancesAnciennes($user, 900, 400);

    $apres = lecturesTableauDeBord($compter);

    expect($apres)->toBe($avant)
        ->and($compter())->toBe(3);
});
