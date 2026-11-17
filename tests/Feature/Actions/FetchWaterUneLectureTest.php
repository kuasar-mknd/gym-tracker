<?php

declare(strict_types=1);

use App\Actions\Tools\FetchWaterHistoryAction;
use App\Actions\Tools\FetchWaterTrackerAction;
use App\Models\User;
use App\Models\WaterLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * Le compteur du jour et le graphique des sept jours lisent les prises une
 * fois (#2351, point 3), là où chacun avait sa lecture. Les deux anciennes
 * lectures sont recopiées ici telles quelles, rejouées sur les mêmes données.
 */

/**
 * L'ancienne lecture du compteur du jour.
 *
 * @return array{logs: mixed, todayTotal: mixed}
 */
function compteurDuJourDAvantUneLecture(User $compte, Carbon $maintenant): array
{
    $debut = $maintenant->copy()->startOfDay();
    $prises = $compte->waterLogs()
        ->whereBetween('consumed_at', [$debut, $debut->copy()->endOfDay()])
        ->orderByDesc('consumed_at')
        ->get();

    return ['logs' => $prises, 'todayTotal' => $prises->sum('amount')];
}

/**
 * L'ancienne lecture du graphique : les prises depuis le début de la fenêtre,
 * sans ordre.
 */
function semaineDAvantUneLecture(User $compte, Carbon $maintenant): mixed
{
    return $compte->waterLogs()
        ->where('consumed_at', '>=', $maintenant->copy()->subDays(6)->startOfDay())
        ->get();
}

it('rend le même compteur et le même graphique que les deux lectures d’avant', function (): void {
    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    $compte = User::factory()->create();
    $voisin = User::factory()->create();

    foreach ([
        ['2026-06-09 00:00:00', 100], // le premier instant de la fenêtre
        ['2026-06-08 23:59:59', 900], // une seconde avant
        ['2026-06-12 08:00:00', 300],
        ['2026-06-15 00:00:00', 250], // le premier instant du jour
        ['2026-06-15 11:00:00', 500],
        ['2026-06-15 11:00:01', 125],
        ['2026-06-15 23:59:59', 75], // le dernier instant du jour, après l'instant donné
        ['2026-06-16 00:00:00', 800], // demain : ni au compteur ni au graphique
    ] as [$quand, $quantite]) {
        WaterLog::factory()->create(['user_id' => $compte->id, 'consumed_at' => Carbon::parse($quand), 'amount' => $quantite]);
    }
    WaterLog::factory()->create(['user_id' => $voisin->id, 'consumed_at' => Carbon::parse('2026-06-15 10:00:00'), 'amount' => 1000]);

    $historique = app(FetchWaterHistoryAction::class);
    $compteur = app(FetchWaterTrackerAction::class);

    $prises = $historique->prisesDeLaSemaine($compte, $maintenant);

    $apresCompteur = $compteur->execute($compte, $maintenant, $prises);
    $apresGraphique = $historique->execute($compte, $maintenant, $prises);

    $avantCompteur = compteurDuJourDAvantUneLecture($compte, $maintenant);

    expect($apresCompteur['todayTotal'])->toBe(950)
        ->and($apresCompteur['todayTotal'])->toBe($avantCompteur['todayTotal'])
        ->and(json_encode($apresCompteur['logs']))->toBe(json_encode($avantCompteur['logs']))
        ->and($apresCompteur['logs']->pluck('amount')->all())->toBe([75, 125, 500, 250])
        ->and($apresGraphique)->toBe($historique->execute($compte, $maintenant))
        ->and(array_column($apresGraphique, 'total'))->toBe([100.0, 0.0, 0.0, 300.0, 0.0, 0.0, 950.0])
        ->and($prises->pluck('id')->sort()->values()->all())->toBe(semaineDAvantUneLecture($compte, $maintenant)->pluck('id')->sort()->values()->all());
});

it('lit les prises une seule fois pour le compteur et le graphique', function (): void {
    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    $compte = User::factory()->create();
    WaterLog::factory()->create(['user_id' => $compte->id, 'consumed_at' => Carbon::parse('2026-06-14 09:00:00'), 'amount' => 300]);

    $compter = static function (callable $appel): int {
        $nombre = 0;
        DB::listen(function (QueryExecuted $requete) use (&$nombre): void {
            if (str_contains($requete->sql, 'from `water_logs`')) {
                $nombre++;
            }
        });
        $appel();

        return $nombre;
    };

    $avant = $compter(function () use ($compte, $maintenant): void {
        compteurDuJourDAvantUneLecture($compte, $maintenant);
        semaineDAvantUneLecture($compte, $maintenant);
    });
    $apres = $compter(function () use ($compte, $maintenant): void {
        $prises = app(FetchWaterHistoryAction::class)->prisesDeLaSemaine($compte, $maintenant);
        app(FetchWaterTrackerAction::class)->execute($compte, $maintenant, $prises);
        app(FetchWaterHistoryAction::class)->execute($compte, $maintenant, $prises);
    });

    expect($avant)->toBe(2)
        ->and($apres)->toBe(1);
});

it('sert la page Hydratation d’une seule lecture des prises', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $compte = User::factory()->create();
    WaterLog::factory()->create(['user_id' => $compte->id, 'consumed_at' => Carbon::parse('2026-06-15 09:00:00'), 'amount' => 400]);

    $lectures = 0;
    DB::listen(function (QueryExecuted $requete) use (&$lectures): void {
        if (str_contains($requete->sql, 'from `water_logs`')) {
            $lectures++;
        }
    });

    $reponse = actingAs($compte)->get(route('tools.water.index'))->assertOk();

    expect($lectures)->toBe(1)
        ->and($reponse->inertiaProps('todayTotal'))->toBe(400)
        ->and($reponse->inertiaProps('history.6.total'))->toBe(400);
});

it('départage deux prises de la même seconde par l’identifiant décroissant', function (): void {
    $maintenant = Carbon::parse('2026-06-15 12:00:00');
    Carbon::setTestNow($maintenant);

    $compte = User::factory()->create();
    $premiere = WaterLog::factory()->create(['user_id' => $compte->id, 'consumed_at' => Carbon::parse('2026-06-15 09:00:00'), 'amount' => 200]);
    $seconde = WaterLog::factory()->create(['user_id' => $compte->id, 'consumed_at' => Carbon::parse('2026-06-15 09:00:00'), 'amount' => 300]);

    $prises = app(FetchWaterHistoryAction::class)->prisesDeLaSemaine($compte, $maintenant);
    $compteur = app(FetchWaterTrackerAction::class)->execute($compte, $maintenant, $prises);

    expect($prises->pluck('id')->all())->toBe([$seconde->id, $premiere->id])
        ->and($compteur['logs']->pluck('id')->all())->toBe([$seconde->id, $premiere->id]);
});
