<?php

declare(strict_types=1);

use App\Actions\Measurements\FetchBodyPartMeasurementsIndexAction;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function lecturesIndex(callable $geste): int
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

function semerMesures(User $user, int $parPartie): void
{
    $lignes = [];

    foreach (['Biceps L', 'Chest', 'Hips', 'Neck', 'Thigh R', 'Waist'] as $partie) {
        for ($jour = 0; $jour < $parPartie; $jour++) {
            $lignes[] = [
                'user_id' => $user->id,
                'part' => $partie,
                'value' => 40 + $jour,
                'unit' => 'cm',
                'measured_at' => Carbon::now()->subDays($jour)->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        BodyPartMeasurement::insert($lot);
    }
}

/**
 * Le `select distinct part` qui ouvrait cette page lisait tout l'historique de
 * l'utilisateur pour n'en tirer que la liste des parties : mesure faite,
 * 8 017 entrees d'index a 1 000 mesures par partie, contre 1 617 a 200.
 *
 * L'enumeration par sauts en lit 25, quelle que soit la profondeur — d'ou
 * l'egalite stricte assertee ici, et non un simple plafond.
 */
it('lit autant d’index avec cinq fois plus d’historique', function (): void {
    $court = User::factory()->create();
    semerMesures($court, 40);

    $long = User::factory()->create();
    semerMesures($long, 200);

    $action = new FetchBodyPartMeasurementsIndexAction();

    // Un premier passage a vide : la table `users` du `factory` fausserait le releve.
    lecturesIndex(fn (): array => $action->execute($court));

    $petit = lecturesIndex(fn (): array => $action->execute($court));
    $grand = lecturesIndex(fn (): array => $action->execute($long));

    expect($grand)->toBe($petit);
});

it('rend la derniere mesure et l’ecart avec la precedente', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Chest', 'value' => 100, 'unit' => 'cm', 'measured_at' => '2026-01-01']);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Chest', 'value' => 104.5, 'unit' => 'cm', 'measured_at' => '2026-02-01']);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Waist', 'value' => 80, 'unit' => 'cm', 'measured_at' => '2026-02-01']);

    $action = new FetchBodyPartMeasurementsIndexAction();
    $resultat = $action->execute($user);

    expect($resultat['latestMeasurements']->all())->toBe([
        ['part' => 'Chest', 'current' => 104.5, 'unit' => 'cm', 'date' => '2026-02-01', 'diff' => 4.5],
        ['part' => 'Waist', 'current' => 80.0, 'unit' => 'cm', 'date' => '2026-02-01', 'diff' => 0.0],
    ]);
});

it('ne voit pas les mesures d’un autre utilisateur', function (): void {
    $user = User::factory()->create();
    $autre = User::factory()->create();
    BodyPartMeasurement::factory()->create(['user_id' => $autre->id, 'part' => 'Chest', 'value' => 100, 'measured_at' => '2026-01-01']);

    $action = new FetchBodyPartMeasurementsIndexAction();

    expect($action->execute($user)['latestMeasurements'])->toBeEmpty();
});
