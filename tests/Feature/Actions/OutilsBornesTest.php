<?php

declare(strict_types=1);

use App\Actions\FetchWorkoutTemplatesAction;
use App\Actions\Measurements\FetchBodyPartMeasurementShowAction;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function lecturesOutils(callable $geste): int
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

function semerOutils(User $user, int $lignes): void
{
    $objectifs = $gabarits = $minuteurs = $mesures = [];

    for ($i = 0; $i < $lignes; $i++) {
        $quand = Carbon::now()->subDays($i);
        $objectifs[] = ['user_id' => $user->id, 'title' => 'O'.$i, 'type' => 'weight', 'target_value' => 100, 'current_value' => 0, 'created_at' => $quand, 'updated_at' => now()];
        $gabarits[] = ['user_id' => $user->id, 'name' => 'G'.$i, 'created_at' => $quand, 'updated_at' => now()];
        $minuteurs[] = ['user_id' => $user->id, 'name' => 'M'.$i, 'rounds' => 3, 'work_seconds' => 30, 'rest_seconds' => 15, 'created_at' => $quand, 'updated_at' => now()];
        $mesures[] = ['user_id' => $user->id, 'part' => 'Chest', 'value' => 90 + $i, 'unit' => 'cm', 'measured_at' => $quand->toDateString(), 'created_at' => now(), 'updated_at' => now()];
    }

    foreach ([['goals', $objectifs], ['workout_templates', $gabarits], ['interval_timers', $minuteurs], ['body_part_measurements', $mesures]] as [$table, $donnees]) {
        foreach (array_chunk($donnees, 500) as $lot) {
            DB::table($table)->insert($lot);
        }
    }
}

/**
 * Ces quatre lectures portaient bien une borne, mais aucun index ne servait
 * leur tri : MySQL triait tout ce que l'utilisateur possede avant d'en prendre
 * cent. La borne bornait la reponse, pas le travail.
 *
 * Les deux tailles comparees sont toutes deux AU-DESSUS des bornes : c'est ce
 * qui rend l'egalite significative.
 */
it('lit autant d’index avec quatre fois plus d’historique', function (): void {
    $court = User::factory()->create();
    semerOutils($court, 250);

    $long = User::factory()->create();
    semerOutils($long, 1000);

    $gabarits = new FetchWorkoutTemplatesAction();
    $mesures = new FetchBodyPartMeasurementShowAction();

    $lectures = fn (User $u): array => [
        'objectifs' => lecturesOutils(fn (): Collection => $u->goals()->latest()->limit(100)->get()),
        'gabarits' => lecturesOutils(fn (): Collection => $gabarits->execute($u)),
        'minuteurs' => lecturesOutils(fn (): Collection => $u->intervalTimers()->latest('created_at')->limit(50)->get()),
        'mesures' => lecturesOutils(fn (): Collection => $mesures->execute($u, 'Chest')),
    ];

    $lectures($court);

    expect($lectures($long))->toBe($lectures($court));
});

it('rend les mesures les plus récentes, dans l’ordre croissant', function (): void {
    $user = User::factory()->create();
    semerOutils($user, 205);

    $action = new FetchBodyPartMeasurementShowAction();
    $histoire = $action->execute($user, 'Chest');
    $valeurs = $histoire->map(fn (BodyPartMeasurement $mesure): float => (float) $mesure->value)->values()->all();

    // Semees a `90 + $i` en remontant le temps : les 200 plus recentes sont
    // donc 90 a 289, rendues de la plus ancienne a la plus recente.
    expect($histoire)->toHaveCount(200)
        ->and($valeurs[0])->toBe(289.0)
        ->and($valeurs[199])->toBe(90.0);
});
