<?php

declare(strict_types=1);

use App\Actions\Exercises\FetchExerciseHistoryAction;
use App\DTOs\Stats\Exercise1RMProgressPoint;
use App\DTOs\Stats\MuscleDistributionStat;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\Stats\ExerciseStatsService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/*
 * La courbe 1RM, la répartition musculaire et le meilleur 1RM de l'historique
 * d'un exercice ne comptent que les séries validées (#1956).
 *
 * Chaque série naît décochée, préremplie par le modèle ou par la valeur
 * proposée. Le volume de séance, les records et les objectifs ne comptaient
 * déjà que les séries cochées ; ces trois lectures-là comptaient tout, et le
 * 1RM y prenait aussi les échauffements. Une série prévue à 140 kg et jamais
 * faite faisait monter la courbe au-dessus du record affiché.
 */

/**
 * Une ligne de développé couché dans une séance d'il y a deux jours.
 *
 * @param  bool  $enCours  Une séance terminée n'accepte plus qu'on coche ses séries.
 */
function ligneDePectorauxPourStats(User $user, bool $enCours = false): WorkoutLine
{
    $exercice = Exercise::factory()->create([
        'user_id' => $user->id,
        'type' => 'strength',
        'category' => 'Pectoraux',
    ]);
    $seance = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-13 10:00:00'),
        'ended_at' => $enCours ? null : Carbon::parse('2026-06-13 11:00:00'),
    ]);

    return WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
}

/**
 * Le record « 1RM estimé » de la ligne, tel que le profil l'affiche.
 */
function record1RMDeLaLigne(User $user, WorkoutLine $ligne): float
{
    $valeur = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('exercise_id', $ligne->exercise_id)
        ->where('type', 'max_1rm')
        ->value('value');

    expect($valeur)->toBeNumeric('aucun record 1RM : le test ne comparerait rien');

    return is_numeric($valeur) ? (float) $valeur : 0.0;
}

/**
 * Les points de la courbe 1RM de la fiche exercice, sur un an.
 *
 * @return list<float>
 */
function courbe1RMDeLaLigne(User $user, WorkoutLine $ligne): array
{
    return array_values(array_map(
        fn (Exercise1RMProgressPoint $point): float => $point->one_rep_max,
        app(ExerciseStatsService::class)->getExercise1RMProgress($user, $ligne->exercise_id, 365),
    ));
}

/**
 * Le meilleur 1RM de chaque séance de l'historique de l'exercice.
 *
 * @return list<float|null>
 */
function meilleurs1RMDeLHistorique(User $user, WorkoutLine $ligne): array
{
    return array_values(app(FetchExerciseHistoryAction::class)
        ->execute($user, Exercise::query()->findOrFail($ligne->exercise_id))
        ->map(fn (array $seance): ?float => $seance['best_1rm'])
        ->all());
}

/**
 * La répartition musculaire, par catégorie.
 *
 * @return array<string, float>
 */
function repartitionMusculairePourStats(User $user): array
{
    return collect(app(ExerciseStatsService::class)->getMuscleDistribution($user))
        ->mapWithKeys(fn (MuscleDistributionStat $part): array => [$part->category => $part->volume])
        ->all();
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

it('aligne la courbe 1RM et le meilleur 1RM de l’historique sur le record, sans la série jamais cochée', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);
    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 140, 'reps' => 5]);

    $record = record1RMDeLaLigne($user, $ligne);

    expect($record)->toBe(116.67)
        ->and(courbe1RMDeLaLigne($user, $ligne))->toBe([$record])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([$record]);
});

it('laisse les échauffements hors du 1RM, comme le record', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 150, 'reps' => 3, 'is_warmup' => true]);

    $record = record1RMDeLaLigne($user, $ligne);

    expect($record)->toBe(116.67)
        ->and(courbe1RMDeLaLigne($user, $ligne))->toBe([$record])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([$record]);
});

/*
 * Une répétition est déjà un maximum : le record la garde telle quelle, quand
 * la formule seule l'aurait gonflée de 3,3 %. Et une série sans répétition ne
 * soulève rien, quel que soit son poids.
 */
it('prend une répétition unique pour un maximum et ignore une série sans répétition, comme le record', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 120, 'reps' => 1]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 200, 'reps' => 0]);

    $record = record1RMDeLaLigne($user, $ligne);

    expect($record)->toBe(120.0)
        ->and(courbe1RMDeLaLigne($user, $ligne))->toBe([$record])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([$record]);
});

/*
 * 27,75 kg x 19 valent exactement 45,325. Le record, calculé en flottant par
 * PHP, affiche 45,32 ; une courbe calculée en décimal par MySQL tombait juste
 * et affichait 45,33.
 */
it('suit le record au centième près, même sur une valeur à mi-chemin', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 27.75, 'reps' => 19]);

    $record = record1RMDeLaLigne($user, $ligne);

    expect($record)->toBe(45.32)
        ->and(courbe1RMDeLaLigne($user, $ligne))->toBe([$record])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([$record]);
});

it('donne à la répartition musculaire le volume de la séance', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);                        // 500
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 40, 'reps' => 10, 'is_warmup' => true]);   // 400, fait
    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 140, 'reps' => 5]);        // jamais fait

    $volumeDeLaSeance = Workout::query()->findOrFail($ligne->workout_id)->workout_volume;

    expect($volumeDeLaSeance)->toBe(900.0)
        ->and(repartitionMusculairePourStats($user))->toBe(['Pectoraux' => $volumeDeLaSeance]);
});

/*
 * Cocher la série la fait entrer partout à la fois : l'écriture invalide les
 * statistiques en cache, et les trois lectures suivent le record.
 */
it('fait entrer la série dans les trois lectures dès qu’elle est cochée', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user, enCours: true);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);
    $prevue = Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 140, 'reps' => 5]);

    expect(courbe1RMDeLaLigne($user, $ligne))->toBe([116.67])
        ->and(repartitionMusculairePourStats($user))->toBe(['Pectoraux' => 500.0]);

    actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $prevue), ['is_completed' => true])
        ->assertOk();

    $record = record1RMDeLaLigne($user, $ligne);

    expect($record)->toBe(163.33)
        ->and(courbe1RMDeLaLigne($user, $ligne))->toBe([$record])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([$record])
        ->and(repartitionMusculairePourStats($user))->toBe(['Pectoraux' => 1200.0]);
});

/*
 * Une séance lancée depuis un modèle puis abandonnée, ou faite seulement
 * d'échauffements, n'a pas de 1RM. Un zéro y traçait une chute à 0 kg sur
 * les courbes de la fiche, quand la courbe de progression omet la séance.
 */
it('ne donne pas de meilleur 1RM à une séance sans série qui puisse établir le record', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 60, 'reps' => 8, 'is_warmup' => true]);

    expect(courbe1RMDeLaLigne($user, $ligne))->toBe([])
        ->and(meilleurs1RMDeLHistorique($user, $ligne))->toBe([null]);
});

/*
 * La page d'exercice tire de cette liste ses graphiques de volume, de charge
 * max, de répétitions… Elle ne peut n'y compter que les séries validées que
 * si chaque série le dit ; la liste, elle, reste complète.
 */
it('envoie à la page d’exercice chaque série avec ses drapeaux de validation et d’échauffement', function (): void {
    $user = User::factory()->create();
    $ligne = ligneDePectorauxPourStats($user);

    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 40, 'reps' => 10, 'is_warmup' => true]);
    Set::factory()->naPasEteFaite()->create(['workout_line_id' => $ligne->id, 'weight' => 140, 'reps' => 5]);

    actingAs($user)
        ->get(route('exercises.show', $ligne->exercise_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Exercises/Show')
            ->where('history.0.best_1rm', 116.67)
            ->where('history.0.sets', [
                ['weight' => 100, 'reps' => 5, 'one_rep_max' => 116.67, 'is_completed' => true, 'is_warmup' => false],
                ['weight' => 40, 'reps' => 10, 'one_rep_max' => 53.33, 'is_completed' => true, 'is_warmup' => true],
                ['weight' => 140, 'reps' => 5, 'one_rep_max' => 163.33, 'is_completed' => false, 'is_warmup' => false],
            ]));
});
