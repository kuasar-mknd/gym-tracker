<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * Des records d'avant les correctifs restent sous la meilleure série éligible
 * de leur exercice : deux synchronisations croisées avant #1984, la plus lente
 * écrivant 100 kg par-dessus 105, l'état même que
 * `coherenceRecordResteSousLaMeilleureSerie()` plante dans
 * `VerifyDataCoherenceTest`. Le contrôle de nuit les signale sans les réparer.
 * Depuis #2185, la reconstruction d'une écriture de l'application annonce le
 * record qu'elle remonte vers une autre série que celle qui le tenait, ou
 * qu'elle crée : une écriture ordinaire (une ancienne séance supprimée, une
 * autre ligne de l'exercice retirée, la série qui tient le record corrigée)
 * annonçait de nouveau « … avec 105 kg », que la synchronisation de la série
 * avait déjà annoncé.
 * `2026_11_05_152528_remonter_les_records_restes_sous_leur_meilleure_serie.php`
 * remonte une fois ces records, et crée le record suivi qui manque, par
 * `PersonalRecordService::reparer()`, sans rien annoncer. La migration
 * n'écrit aucun schéma : elle se joue dans la transaction du test, sur la
 * file synchrone, où les annonces partent dans la requête.
 */

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function recordsRemontesMigrer(): void
{
    $migration = require database_path('migrations/2026_11_05_152528_remonter_les_records_restes_sous_leur_meilleure_serie.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Un pratiquant qui a l'annonce de ses records activée, une ancienne séance
 * avec une série de squat de 50 kg, et une séance du jour avec deux lignes de
 * squat : la série de 100 kg et celle de 105 kg sur la première, une série de
 * 40 kg sur la seconde. Chaque série passe par `Set::saved` : ses records
 * s'établissent, et ceux de 105 kg sont annoncés.
 *
 * @return array{compte: User, ancienne: Workout, autreLigne: WorkoutLine, legere: Set, lourde: Set}
 */
function recordsRemontesCompte(): array
{
    $compte = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $compte->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => false,
    ]);
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Squat', 'type' => 'strength']);

    $ancienne = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subWeeks(3), 'ended_at' => now()->subWeeks(3)->addHour()]);
    recordsRemontesSerie(WorkoutLine::factory()->create(['workout_id' => $ancienne->id, 'exercise_id' => $exercice->id]), 50);

    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $autreLigne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    recordsRemontesSerie($autreLigne, 40);

    return [
        'compte' => $compte,
        'ancienne' => $ancienne,
        'autreLigne' => $autreLigne,
        'legere' => recordsRemontesSerie($ligne, 100),
        'lourde' => recordsRemontesSerie($ligne, 105),
    ];
}

/**
 * Une série faite d'une répétition, par le modèle : `Set::saved` la
 * synchronise dans la requête.
 */
function recordsRemontesSerie(WorkoutLine $ligne, float $poids): Set
{
    return Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => 1, 'is_warmup' => false, 'is_completed' => true]);
}

/**
 * Les lignes des records du compte, telles qu'en base, par identifiant.
 *
 * @return array<array-key, array<mixed>>
 */
function recordsRemontesLignes(User $compte): array
{
    return DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->orderBy('id')
        ->get()
        ->keyBy('id')
        ->map(fn (object $ligne): array => get_object_vars($ligne))
        ->all();
}

/**
 * Le record du type, sa valeur et sa série.
 *
 * @return array{0: float, 1: int|null}
 */
function recordsRemontesRecord(User $compte, string $type): array
{
    $record = PersonalRecord::query()->where('user_id', $compte->id)->where('type', $type)->sole();

    return [(float) $record->value, $record->set_id];
}

/**
 * Les messages des notifications du compte, par identifiant.
 *
 * @return array<array-key, string>
 */
function recordsRemontesNotifications(User $compte): array
{
    return DB::table('notifications')
        ->where('notifiable_type', $compte->getMorphClass())
        ->where('notifiable_id', $compte->id)
        ->pluck('data', 'id')
        ->map(static function (mixed $donnees): string {
            $decodees = is_string($donnees) ? json_decode($donnees, true, flags: JSON_THROW_ON_ERROR) : null;

            return is_array($decodees) && is_string($decodees['message'] ?? null) ? $decodees['message'] : '';
        })
        ->all();
}

/**
 * Les messages des notifications du compte arrivées depuis ce relevé, triés.
 *
 * @param  array<array-key, string>  $releve
 * @return list<string>
 */
function recordsRemontesAnnoncesDepuis(User $compte, array $releve): array
{
    $nouvelles = array_values(array_diff_key(recordsRemontesNotifications($compte), $releve));
    sort($nouvelles);

    return $nouvelles;
}

/**
 * Une écriture ordinaire de l'application qui reconstruit les records du
 * squat, par la route que la page appelle.
 *
 * @param  array{compte: User, ancienne: Workout, autreLigne: WorkoutLine, legere: Set, lourde: Set}  $scene
 */
function recordsRemontesEcrire(string $ecriture, array $scene): void
{
    match ($ecriture) {
        'ancienne séance supprimée' => actingAs($scene['compte'], 'web')
            ->delete(route('workouts.destroy', $scene['ancienne']))
            ->assertRedirect(),
        'autre ligne de l’exercice retirée' => actingAs($scene['compte'], 'sanctum')
            ->deleteJson(route('api.v1.workout-lines.destroy', $scene['autreLigne']))
            ->assertNoContent(),
        'série de 100 kg corrigée à 98' => actingAs($scene['compte'], 'sanctum')
            ->patchJson(route('api.v1.sets.update', $scene['legere']), ['weight' => 98])
            ->assertOk(),
        default => throw new LogicException('Écriture inconnue : '.$ecriture),
    };
}

dataset('écritures qui reconstruisent', [
    'une ancienne séance supprimée' => ['ancienne séance supprimée'],
    'une autre ligne de l’exercice retirée' => ['autre ligne de l’exercice retirée'],
    'la série qui tient le record corrigée' => ['série de 100 kg corrigée à 98'],
]);

it('remonte sans l’annoncer le record resté sous la meilleure série, qu’une écriture de l’application n’annonce plus de nouveau', function (string $ecriture): void {
    $scene = recordsRemontesCompte();
    $compte = $scene['compte'];
    $releve = recordsRemontesNotifications($compte);

    // La série de 105 kg a été annoncée à sa synchronisation.
    expect($releve)->toContain("Félicitations ! Tu as battu ton record de poids maximum sur l'exercice Squat avec 105\u{00A0}kg.");

    // L'écart de #1984 : la plus lente des deux synchronisations a reposé le
    // poids maximum à 100 kg, sur la série de 100.
    DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->where('type', 'max_weight')
        ->update(['value' => 100, 'secondary_value' => 1, 'set_id' => $scene['legere']->id]);

    recordsRemontesMigrer();

    $apresLaMigration = recordsRemontesRecord($compte, 'max_weight');
    $annoncesDeLaMigration = recordsRemontesAnnoncesDepuis($compte, $releve);

    // Rejouée, elle ne trouve plus rien à reprendre.
    $lignes = recordsRemontesLignes($compte);
    recordsRemontesMigrer();

    expect(recordsRemontesLignes($compte))->toBe($lignes);

    recordsRemontesEcrire($ecriture, $scene);

    // Rien n'est annoncé de nouveau, ni par l'écriture ni par la migration,
    // qui a remonté le record vers la série de 105 kg.
    expect(recordsRemontesAnnoncesDepuis($compte, $releve))->toBe([])
        ->and($annoncesDeLaMigration)->toBe([])
        ->and($apresLaMigration)->toBe([105.0, $scene['lourde']->id])
        ->and(recordsRemontesRecord($compte, 'max_weight'))->toBe([105.0, $scene['lourde']->id]);
})->with('écritures qui reconstruisent');

it('crée sans l’annoncer le record suivi qui manque à un exercice qui a une série éligible, qu’une écriture de l’application n’annonce pas ensuite', function (): void {
    $scene = recordsRemontesCompte();
    $compte = $scene['compte'];
    $releve = recordsRemontesNotifications($compte);

    // Le 1RM tenu sous un type hérité, que l'application ne tient plus
    // (#1811) : rien ne tient `max_1rm`.
    DB::table('personal_records')->where('user_id', $compte->id)->where('type', 'max_1rm')->update(['type' => '1RM']);

    recordsRemontesMigrer();

    $apresLaMigration = PersonalRecord::query()->where('user_id', $compte->id)->where('type', 'max_1rm')->get(['value', 'set_id'])->toArray();
    $annoncesDeLaMigration = recordsRemontesAnnoncesDepuis($compte, $releve);

    recordsRemontesEcrire('ancienne séance supprimée', $scene);

    expect(recordsRemontesAnnoncesDepuis($compte, $releve))->toBe([])
        ->and($annoncesDeLaMigration)->toBe([])
        ->and($apresLaMigration)->toBe([['value' => '105.00', 'set_id' => $scene['lourde']->id]])
        // La ligne héritée reste au contrôle « type des records ».
        ->and(DB::table('personal_records')->where('user_id', $compte->id)->whereRaw('CAST(type AS BINARY) = ?', ['1RM'])->count())->toBe(1);
});

/*
 * Un record resté AU-DESSUS de sa meilleure série (celui d'une série supprimée
 * avant #1476, détaché) n'est jamais annoncé par une reconstruction, qui le
 * descend. La migration le laisse au contrôle de nuit, qui ne répare pas à
 * dessein, et à `--repair`.
 */
it('laisse tels quels les records qui valent leur meilleure série ou restent au-dessus', function (): void {
    $scene = recordsRemontesCompte();
    $compte = $scene['compte'];

    DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->where('type', 'max_volume_set')
        ->update(['value' => 500, 'set_id' => null]);

    $lignes = recordsRemontesLignes($compte);

    recordsRemontesMigrer();

    expect(recordsRemontesLignes($compte))->toBe($lignes);
});

/*
 * Une ligne d'un type que le modèle ne lit pas ('MAX_WEIGHT', que la
 * collation confond avec 'max_weight') ferait lever la reconstruction
 * (`ValueError` au cast du type), comme toute reconstruction que l'application
 * tenterait sur cet exercice : la migration laisse l'exercice au contrôle
 * « type des records » plutôt que d'arrêter le déploiement.
 */
it('laisse de côté, sans échouer, un exercice dont un record porte un type que le modèle ne lit pas', function (): void {
    $scene = recordsRemontesCompte();
    $compte = $scene['compte'];

    DB::table('personal_records')
        ->where('user_id', $compte->id)
        ->where('type', 'max_weight')
        ->update(['type' => 'MAX_WEIGHT']);

    $lignes = recordsRemontesLignes($compte);

    recordsRemontesMigrer();

    expect(recordsRemontesLignes($compte))->toBe($lignes);
});
